<?php
/**
 * Multisite: pannello di rete (Network Admin, permesso manage_network_options).
 *
 * - Form: tutti i form di tutti i siti (dalla cache di scansione di ogni sito), con colonna Sito, filtro per sito,
 *   richieste, stato dell'ultimo webhook e link alla gestione del form nel suo sito.
 * - Richieste: vista unica di tutti i siti, filtrabile per sito, con export CSV.
 * - Impostazioni di rete: template email, webhook e retention di default (get_site_option) e blocco dei campi sui siti.
 * - "Riscansiona la rete" lavora a blocchi di 10 siti (una chiamata AJAX per blocco, con l'avanzamento a schermo);
 *   un cron giornaliero riscansiona fino a 20 siti con la cache più vecchia di un giorno.
 * I dati degli altri siti si leggono con switch_to_blog() / restore_current_blog().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Network {

	const PAGE  = 'lu3g-form-manager-network';
	const CAP   = 'manage_network_options';
	const CRON  = 'lu3g_fm_network_scan';
	const BATCH = 10;

	public static function init() {
		add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_lu3g_fm_net', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_lu3g_fm_net_export', array( __CLASS__, 'export' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_scan' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( LU3G_FM_FILE ), array( __CLASS__, 'action_links' ) );
		if ( is_main_site() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), network_admin_url( 'admin.php' ) );
	}

	public static function menu() {
		$svg  = (string) file_get_contents( LU3G_FM_PATH . 'assets/lu3g-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$hook = add_menu_page( 'LU3G Form Manager', 'LU3G Form', self::CAP, self::PAGE, array( __CLASS__, 'render' ), 'data:image/svg+xml;base64,' . base64_encode( $svg ), 22 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Apri', 'lu3g-form-manager' ) . '</a>' );
		return $links;
	}

	public static function load() {
		require_once LU3G_FM_PATH . 'includes/class-admin.php';
		add_action(
			'admin_enqueue_scripts',
			function () {
				LU3G_FM_Admin::assets(
					array(
						'action' => 'lu3g_fm_net',
						'nonce'  => wp_create_nonce( 'lu3g_fm_net' ),
					)
				);
			}
		);
		add_filter(
			'admin_body_class',
			function ( $c ) {
				return $c . ' lu3g-fm-page';
			}
		);
	}

	/** Siti della rete (id => nome). */
	public static function sites( $limit = 1000 ) {
		$out = array();
		foreach ( get_sites( array( 'number' => $limit, 'deleted' => 0, 'archived' => 0 ) ) as $site ) {
			$details                 = get_blog_details( $site->blog_id );
			$out[ (int) $site->blog_id ] = $details && $details->blogname ? $details->blogname : $site->domain . $site->path;
		}
		return $out;
	}

	/** Il plugin ha già le sue tabelle in questo sito? (se attivato solo su alcuni siti, gli altri no) */
	private static function has_tables() {
		return (bool) get_option( 'lu3g_fm_db' );
	}

	/* ------------------------------------------------------------------ pagina */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'forms'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sites = self::sites();
		$site  = isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap lu3g-fm"><h1 class="screen-reader-text">LU3G Form Manager</h1><hr class="wp-header-end">';
		LU3G_FM_Admin::hero(
			__( 'Form della rete', 'lu3g-form-manager' ),
			__( 'Tutti i form di Elementor di tutti i siti, le richieste e le impostazioni di default.', 'lu3g-form-manager' ),
			array(
				__( 'Siti', 'lu3g-form-manager' ) => count( $sites ),
			),
			'<button type="button" class="fm-btn fm-btn--light" data-net-scan><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.9-3.9M4 4v4h4M4 13a8 8 0 0 0 14.9 3.9M20 20v-4h-4"/></svg>' . esc_html__( 'Riscansiona la rete', 'lu3g-form-manager' ) . '</button>'
		);
		echo '<div class="fm-progress" data-net-progress hidden><div class="fm-progress__bar"><span></span></div><small></small></div>';

		echo '<nav class="fm-tabs">';
		foreach ( array( 'forms' => __( 'Form', 'lu3g-form-manager' ), 'submissions' => __( 'Richieste', 'lu3g-form-manager' ), 'settings' => __( 'Impostazioni di rete', 'lu3g-form-manager' ) ) as $slug => $label ) {
			printf( '<a href="%1$s" class="%2$s">%3$s</a>', esc_url( self::url( array( 'tab' => $slug ) ) ), $slug === $tab ? 'is-current' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'settings' === $tab ) {
			self::section_settings();
		} elseif ( 'submissions' === $tab ) {
			self::section_submissions( $sites, $site );
		} else {
			self::section_forms( $sites, $site );
		}

		echo '<div class="fm-modal" data-modal hidden><div class="fm-modal__box" role="dialog" aria-modal="true"><button type="button" class="fm-modal__close" data-close aria-label="' . esc_attr__( 'Chiudi', 'lu3g-form-manager' ) . '">×</button><div data-modal-body></div></div></div>';
		echo '<div class="fm-toasts" aria-live="polite" data-toasts></div><footer class="fm-foot">LU3G Form Manager ' . esc_html( LU3G_FM_VERSION ) . '</footer></div>';
	}

	private static function site_filter( array $sites, $current, $tab ) {
		echo '<form class="fm-filters" method="get" action="' . esc_url( network_admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<select name="site" onchange="this.form.submit()"><option value="0">' . esc_html__( 'Tutti i siti', 'lu3g-form-manager' ) . '</option>';
		foreach ( $sites as $id => $name ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $id, selected( $current, $id, false ), esc_html( $name ) );
		}
		echo '</select>';
	}

	private static function section_forms( array $sites, $only ) {
		echo '<div class="fm-card">';
		self::site_filter( $sites, $only, 'forms' );
		echo '<input type="search" class="fm-search" placeholder="' . esc_attr__( 'Cerca…', 'lu3g-form-manager' ) . '" data-filter-rows></form>';
		echo '<div class="fm-table-wrap"><table class="fm-table" data-rows><thead><tr><th>' . esc_html__( 'Sito', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Form', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Si trova in', 'lu3g-form-manager' ) . '</th><th class="num">' . esc_html__( 'Richieste', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Webhook', 'lu3g-form-manager' ) . '</th><th></th></tr></thead><tbody>';
		$rows = 0;
		foreach ( $sites as $id => $name ) {
			if ( $only && $only !== $id ) {
				continue;
			}
			switch_to_blog( $id );
			$cache  = get_option( LU3G_FM_Scanner::CACHE );
			$forms  = is_array( $cache ) && isset( $cache['forms'] ) ? $cache['forms'] : null;
			$tables = self::has_tables();
			$counts = $tables ? LU3G_FM_DB::counts() : array();
			$status = $tables ? LU3G_FM_DB::webhook_status() : array();
			if ( null === $forms ) {
				printf( '<tr data-text="%1$s"><td>%2$s</td><td colspan="5" class="fm-muted">%3$s</td></tr>', esc_attr( strtolower( $name ) ), esc_html( $name ), esc_html__( 'Non ancora scansionato: premi "Riscansiona la rete".', 'lu3g-form-manager' ) );
			}
			foreach ( (array) $forms as $key => $f ) {
				$st    = $status[ $key ] ?? null;
				$hook  = $tables ? LU3G_FM_Settings::webhook_for( $key ) : null;
				$badge = ! $hook ? '<span class="fm-pill">' . esc_html__( 'Nessuno', 'lu3g-form-manager' ) . '</span>'
					: ( ! $st ? '<span class="fm-pill fm-pill--info">' . esc_html__( 'Mai chiamato', 'lu3g-form-manager' ) . '</span>'
					: ( $st['ok'] ? '<span class="fm-pill fm-pill--ok">OK</span>' : '<span class="fm-pill fm-pill--err">' . esc_html__( 'Errore', 'lu3g-form-manager' ) . '</span>' ) );
				$manage = get_admin_url( $id, 'admin.php?page=lu3g-form-manager&form=' . rawurlencode( $key ) );
				printf(
					'<tr style="--i:%1$d" data-text="%2$s"><td>%3$s</td><td><a class="fm-strong" href="%4$s">%5$s</a><small class="fm-mono">%6$s</small></td><td>%7$s<small>%8$s</small></td><td class="num">%9$s</td><td>%10$s</td><td class="fm-actions"><a class="fm-btn fm-btn--small" href="%4$s">%11$s</a></td></tr>',
					(int) min( $rows++, 30 ),
					esc_attr( strtolower( $name . ' ' . $f['name'] . ' ' . $f['post_title'] ) ),
					esc_html( $name ),
					esc_url( $manage ),
					esc_html( $f['name'] ),
					esc_html( 'ID ' . $f['element_id'] ),
					esc_html( $f['post_title'] ),
					esc_html( LU3G_FM_Scanner::type_label( $f['post_type'] ) ),
					$tables ? (int) ( $counts[ $key ]['n'] ?? 0 ) : '—',
					$tables ? $badge : '<span class="fm-pill">' . esc_html__( 'Plugin non attivo', 'lu3g-form-manager' ) . '</span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- costruito escapato
					esc_html__( 'Gestisci', 'lu3g-form-manager' )
				);
			}
			restore_current_blog();
		}
		echo '</tbody></table></div></div>';
	}

	private static function section_submissions( array $sites, $only ) {
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 25;
		$res    = self::collect( $sites, $only, $search, $page * $per );
		$rows   = array_slice( $res['rows'], ( $page - 1 ) * $per, $per );

		echo '<div class="fm-card">';
		self::site_filter( $sites, $only, 'submissions' );
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Cerca nei campi…', 'lu3g-form-manager' ) . '"><button class="fm-btn">' . esc_html__( 'Filtra', 'lu3g-form-manager' ) . '</button>';
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'lu3g_fm_net_export', 'site' => $only, 's' => $search ), admin_url( 'admin-post.php' ) ), 'lu3g_fm_net_export' ); // Network Admin non ha un suo admin-post.php
		echo '<a class="fm-btn fm-btn--primary" href="' . esc_url( $export ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M5 20h14"/></svg>' . esc_html__( 'Esporta CSV', 'lu3g-form-manager' ) . '</a></form>';

		if ( ! $rows ) {
			echo '<p class="fm-empty">' . esc_html__( 'Nessuna richiesta.', 'lu3g-form-manager' ) . '</p></div>';
			return;
		}
		echo '<div class="fm-table-wrap"><table class="fm-table"><thead><tr><th>' . esc_html__( 'Data', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Sito', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Form', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Anteprima', 'lu3g-form-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $r ) {
			$preview = array();
			foreach ( array_slice( (array) json_decode( (string) $r['fields'], true ), 0, 3 ) as $f ) {
				$preview[] = wp_html_excerpt( is_array( $f['value'] ?? '' ) ? implode( ', ', $f['value'] ) : (string) ( $f['value'] ?? '' ), 50, '…' );
			}
			printf(
				'<tr style="--i:%1$d"><td class="fm-nowrap">%2$s</td><td>%3$s</td><td><a href="%4$s">%5$s</a></td><td>%6$s</td></tr>',
				(int) $i,
				esc_html( wp_date( 'd/m/Y H:i', strtotime( $r['created'] . ' UTC' ) ) ),
				esc_html( $r['site'] ),
				esc_url( get_admin_url( (int) $r['blog_id'], 'admin.php?page=lu3g-form-manager&form=' . rawurlencode( $r['form_key'] ) . '&tab=submissions' ) ),
				esc_html( $r['form_name'] ),
				esc_html( implode( ' · ', array_filter( $preview ) ) )
			);
		}
		echo '</tbody></table></div>';
		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) {
			echo '<nav class="fm-pager">';
			for ( $p = max( 1, $page - 4 ); $p <= min( $pages, $page + 4 ); $p++ ) {
				printf( '<a href="%1$s"%2$s>%3$d</a>', esc_url( self::url( array( 'tab' => 'submissions', 'site' => $only, 's' => $search, 'paged' => $p ) ) ), $p === $page ? ' aria-current="page"' : '', (int) $p );
			}
			echo '</nav>';
		}
		echo '</div>';
	}

	/**
	 * Richieste di più siti, dalla più recente: da ogni sito le prime $limit righe, poi si uniscono e si ordinano.
	 * ($limit = 0: tutte, per l'export.)
	 */
	private static function collect( array $sites, $only, $search, $limit ) {
		$all   = array();
		$total = 0;
		foreach ( $sites as $id => $name ) {
			if ( $only && $only !== $id ) {
				continue;
			}
			switch_to_blog( $id );
			if ( self::has_tables() ) {
				$res    = LU3G_FM_DB::query_submissions( array( 'search' => $search, 'per_page' => $limit, 'page' => 1 ) );
				$total += $res['total'];
				foreach ( $res['rows'] as $r ) {
					$r['site']    = $name;
					$r['blog_id'] = $id;
					$all[]        = $r;
				}
			}
			restore_current_blog();
		}
		usort(
			$all,
			function ( $a, $b ) {
				return strcmp( $b['created'], $a['created'] );
			}
		);
		return array(
			'rows'  => $limit ? array_slice( $all, 0, $limit ) : $all,
			'total' => $total,
		);
	}

	private static function section_settings() {
		$net = LU3G_FM_Settings::network();
		echo '<form class="fm-form" data-net-settings autocomplete="off">';
		echo '<div class="fm-card"><h3>' . esc_html__( 'Template delle email di default', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'I siti ereditano questi valori e possono personalizzarli, tranne quelli bloccati.', 'lu3g-form-manager' ) . '</p>';
		LU3G_FM_Admin::setting( 'logo', $net, $net, true, 'media', true );
		LU3G_FM_Admin::setting( 'color', $net, $net, true, 'color', true );
		LU3G_FM_Admin::setting( 'footer', $net, $net, true, 'textarea', true );
		echo '</div><div class="fm-card"><h3>' . esc_html__( 'Webhook di default', 'lu3g-form-manager' ) . '</h3>';
		LU3G_FM_Admin::setting( 'webhook_enabled', $net, $net, true, 'switch', true );
		LU3G_FM_Admin::setting( 'webhook_url', $net, $net, true, 'url', true );
		LU3G_FM_Admin::setting( 'webhook_method', $net, $net, true, 'method', true );
		echo '</div><div class="fm-card"><h3>' . esc_html__( 'Retention di default', 'lu3g-form-manager' ) . '</h3>';
		LU3G_FM_Admin::setting( 'retention_months', $net, $net, true, 'number', true );
		echo '</div><div class="fm-savebar" data-savebar><span>' . esc_html__( 'Modifiche non salvate', 'lu3g-form-manager' ) . '</span><button type="submit" class="fm-btn fm-btn--primary">' . esc_html__( 'Salva', 'lu3g-form-manager' ) . '</button></div></form>';
	}

	/* ------------------------------------------------------------------ azioni */

	public static function ajax() {
		check_ajax_referer( 'lu3g_fm_net', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Non hai i permessi per questa operazione.', 'lu3g-form-manager' ) ), 403 );
		}
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$data = isset( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = is_array( $data ) ? $data : array();

		if ( 'net_scan' === $op ) {
			$offset = absint( $data['offset'] ?? 0 );
			$ids    = get_sites( array( 'fields' => 'ids', 'number' => self::BATCH, 'offset' => $offset, 'deleted' => 0, 'archived' => 0 ) );
			$total  = (int) get_sites( array( 'count' => true, 'deleted' => 0, 'archived' => 0 ) );
			$found  = 0;
			foreach ( $ids as $id ) {
				switch_to_blog( $id );
				$found += count( LU3G_FM_Scanner::scan()['forms'] );
				restore_current_blog();
			}
			wp_send_json_success(
				array(
					'next'  => $offset + count( $ids ),
					'total' => $total,
					'found' => $found,
					'done'  => count( $ids ) < self::BATCH || $offset + count( $ids ) >= $total,
				)
			);
		}
		if ( 'save_settings' === $op ) {
			LU3G_FM_Settings::save_network( isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(), isset( $data['locks'] ) ? (array) $data['locks'] : array() );
			wp_send_json_success( array( 'message' => __( 'Impostazioni di rete salvate.', 'lu3g-form-manager' ), 'reload' => true ) );
		}
		wp_send_json_error( array( 'message' => __( 'Azione sconosciuta.', 'lu3g-form-manager' ) ) );
	}

	public static function export() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'lu3g_fm_net_export' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per questa operazione.', 'lu3g-form-manager' ) );
		}
		require_once LU3G_FM_PATH . 'includes/class-admin.php';
		$res = self::collect( self::sites(), isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0, isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', 0 );
		LU3G_FM_Admin::csv( $res['rows'], 'richieste-rete-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	/** Cron: riscansiona fino a 20 siti con la cache più vecchia di un giorno (o mai scansionati). */
	public static function cron_scan() {
		$done = 0;
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 2000, 'deleted' => 0, 'archived' => 0 ) ) as $id ) {
			switch_to_blog( $id );
			if ( time() - LU3G_FM_Scanner::last_scan() > DAY_IN_SECONDS ) {
				LU3G_FM_Scanner::scan();
				$done++;
			}
			restore_current_blog();
			if ( $done >= 20 ) {
				break;
			}
		}
	}
}
