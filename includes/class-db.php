<?php
/**
 * Tabelle del plugin e retention.
 *
 * - {prefix}lu3g_fm_submissions: una riga per invio (campi in JSON, pagina, IP, user agent).
 * - {prefix}lu3g_fm_webhook_log: una riga per chiamata al webhook (payload, stato HTTP, risposta, errore, durata).
 * - Multisite: ogni sito ha le sue tabelle ($wpdb->prefix). Con l'attivazione di rete si creano su tutti i siti (a
 *   blocchi, vedi install_network()), su quelli creati dopo (wp_initialize_site) e comunque al primo caricamento di un
 *   sito che non le ha (init). Quando un sito viene eliminato le sue tabelle vanno via con lui (wp_uninitialize_site).
 * - Retention: una volta al giorno si cancellano le richieste più vecchie dei mesi impostati (0 = mai) e i log dei
 *   webhook più vecchi di 90 giorni (o della retention, se è più breve).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_DB {

	const VERSION = '1';
	const CRON    = 'lu3g_fm_retention';

	public static function submissions() {
		global $wpdb;
		return $wpdb->prefix . 'lu3g_fm_submissions';
	}

	public static function webhook_log() {
		global $wpdb;
		return $wpdb->prefix . 'lu3g_fm_webhook_log';
	}

	public static function init() {
		if ( get_option( 'lu3g_fm_db' ) !== self::VERSION ) {
			self::install();
		}
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 200 );
			add_action( 'wp_uninitialize_site', array( __CLASS__, 'on_delete_site' ), 5 );
		}
		add_action( self::CRON, array( __CLASS__, 'retention' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$s       = self::submissions();
		$w       = self::webhook_log();

		dbDelta(
			"CREATE TABLE {$s} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_key varchar(80) NOT NULL,
				form_name varchar(200) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				page_url text NULL,
				fields longtext NOT NULL,
				ip varchar(64) NOT NULL DEFAULT '',
				user_agent text NULL,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY form_key (form_key),
				KEY created (created)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$w} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_key varchar(80) NOT NULL,
				submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
				url text NOT NULL,
				method varchar(10) NOT NULL DEFAULT 'POST',
				payload longtext NULL,
				status_code smallint(5) unsigned NOT NULL DEFAULT 0,
				response text NULL,
				error text NULL,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				is_test tinyint(1) NOT NULL DEFAULT 0,
				created datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY form_key (form_key),
				KEY created (created)
			) {$charset};"
		);
		update_option( 'lu3g_fm_db', self::VERSION, false );
	}

	/** Attivazione di rete: tabelle su tutti i siti (fino a 500 subito; gli altri le creano al primo caricamento). */
	public static function install_network() {
		$ids = get_sites( array( 'fields' => 'ids', 'number' => 500 ) );
		foreach ( $ids as $id ) {
			switch_to_blog( $id );
			self::install();
			restore_current_blog();
		}
	}

	public static function on_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( LU3G_FM_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			self::install();
			restore_current_blog();
		}
	}

	public static function on_delete_site( $site ) {
		global $wpdb;
		switch_to_blog( $site->blog_id );
		$s = self::submissions();
		$w = self::webhook_log();
		$wpdb->query( "DROP TABLE IF EXISTS {$s}, {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		restore_current_blog();
	}

	/** Stato dell'ultimo webhook per form: [ form_key => [ 'ok' => bool, 'code' => int, 'time' => datetime ] ]. */
	public static function webhook_status() {
		global $wpdb;
		$w    = self::webhook_log();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT l.form_key, l.status_code, l.error, l.created FROM {$w} l INNER JOIN (SELECT form_key, MAX(id) AS id FROM {$w} WHERE is_test = 0 GROUP BY form_key) m ON m.id = l.id", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$code                   = (int) $r['status_code'];
			$out[ $r['form_key'] ] = array(
				'ok'   => $code >= 200 && $code < 300 && empty( $r['error'] ),
				'code' => $code,
				'time' => $r['created'],
			);
		}
		return $out;
	}

	public static function retention() {
		global $wpdb;
		$months = (int) LU3G_FM_Settings::general()['retention_months'];
		$s      = self::submissions();
		$w      = self::webhook_log();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $months > 0 ) {
			$limit = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$s} WHERE created < %s", $limit ) );
		}
		$days  = $months > 0 ? min( 90, $months * 30 ) : 90;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$w} WHERE created < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ richieste */

	public static function insert_submission( array $row ) {
		global $wpdb;
		$wpdb->insert( self::submissions(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function get_submission( $id ) {
		global $wpdb;
		$s = self::submissions();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$s} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Richieste filtrate.
	 *
	 * @param array $args form_key, search, from (Y-m-d), to (Y-m-d), page, per_page (0 = tutte, per l'export).
	 */
	public static function query_submissions( $args ) {
		global $wpdb;
		$args = wp_parse_args( $args, array( 'form_key' => '', 'search' => '', 'from' => '', 'to' => '', 'page' => 1, 'per_page' => 25 ) );
		$s    = self::submissions();
		$w    = array( '1=1' );
		$v    = array();
		if ( '' !== $args['form_key'] ) {
			$w[] = 'form_key = %s';
			$v[] = $args['form_key'];
		}
		if ( '' !== $args['search'] ) {
			$w[] = 'fields LIKE %s';
			$v[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ) {
			$w[] = 'created >= %s';
			$v[] = get_gmt_from_date( $args['from'] . ' 00:00:00' );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ) {
			$w[] = 'created <= %s';
			$v[] = get_gmt_from_date( $args['to'] . ' 23:59:59' );
		}
		$where = implode( ' AND ', $w );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
		$count = "SELECT COUNT(*) FROM {$s} WHERE {$where}";
		$total = (int) ( $v ? $wpdb->get_var( $wpdb->prepare( $count, $v ) ) : $wpdb->get_var( $count ) );
		$sql   = "SELECT * FROM {$s} WHERE {$where} ORDER BY id DESC";
		if ( (int) $args['per_page'] > 0 ) {
			$per  = min( 100, (int) $args['per_page'] );
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per, ( max( 1, (int) $args['page'] ) - 1 ) * $per );
		}
		$rows = $v ? $wpdb->get_results( $wpdb->prepare( $sql, $v ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	public static function delete_submission( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::submissions(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Numero di invii per form: [ form_key => n ]. */
	public static function counts() {
		global $wpdb;
		$s    = self::submissions();
		$rows = $wpdb->get_results( "SELECT form_key, COUNT(*) AS n, MAX(created) AS last FROM {$s} GROUP BY form_key", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['form_key'] ] = array(
				'n'    => (int) $r['n'],
				'last' => $r['last'],
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ log webhook */

	public static function insert_webhook_log( array $row ) {
		global $wpdb;
		$wpdb->insert( self::webhook_log(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function get_webhook_log( $id ) {
		global $wpdb;
		$w = self::webhook_log();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$w} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function query_webhook_log( $form_key = '', $page = 1, $per = 25 ) {
		global $wpdb;
		$w     = self::webhook_log();
		$where = '' !== $form_key ? $wpdb->prepare( 'WHERE form_key = %s', $form_key ) : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$w} {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_key, submission_id, url, method, status_code, error, duration_ms, is_test, created FROM {$w} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}
