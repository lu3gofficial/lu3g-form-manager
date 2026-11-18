<?php
/**
 * Scansione dei form di Elementor Pro.
 *
 * Legge _elementor_data di pagine, post, template e popup (tutti i tipi di contenuto non nel cestino) e trova i widget
 * "form". Ogni form ha una chiave "id post:id elemento", la stessa che Elementor Pro usa all'invio: per un form dentro
 * un template globale (widget globale, popup, header...) il post è il template, e la chiave coincide con quella che
 * l'invio porta in form_post_id.
 *
 * Il risultato resta in cache nell'opzione "lu3g_fm_forms" e si rifà con "Riscansiona" (o dal cron di rete).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Scanner {

	const CACHE = 'lu3g_fm_forms';

	/** Form in cache; se la cache non c'è ancora, scansiona. */
	public static function forms() {
		$c = get_option( self::CACHE );
		if ( ! is_array( $c ) || ! isset( $c['forms'] ) ) {
			$c = self::scan();
		}
		return $c['forms'];
	}

	public static function last_scan() {
		$c = get_option( self::CACHE );
		return is_array( $c ) && isset( $c['time'] ) ? (int) $c['time'] : 0;
	}

	public static function form( $key ) {
		$forms = self::forms();
		return isset( $forms[ $key ] ) ? $forms[ $key ] : null;
	}

	/** Scansiona il sito corrente e salva la cache. */
	public static function scan() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_type, p.post_status, m.meta_value
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = '_elementor_data'
			AND m.meta_value LIKE '%\"widgetType\":\"form\"%'
			AND p.post_type NOT IN ('revision','nav_menu_item')
			AND p.post_status NOT IN ('trash','auto-draft','inherit')",
			ARRAY_A
		);

		$forms = array();
		foreach ( (array) $rows as $row ) {
			$data = json_decode( $row['meta_value'], true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$found = array();
			self::walk( $data, $found );
			foreach ( $found as $el ) {
				$key           = (int) $row['ID'] . ':' . $el['id'];
				$forms[ $key ] = self::describe( $el, $row, $key );
			}
		}

		uasort(
			$forms,
			function ( $a, $b ) {
				return strcasecmp( $a['post_title'] . $a['name'], $b['post_title'] . $b['name'] );
			}
		);

		$cache = array(
			'time'  => time(),
			'forms' => $forms,
		);
		update_option( self::CACHE, $cache, false );
		return $cache;
	}

	/** Cerca i widget form in profondità (sezioni, colonne, contenitori annidati). */
	private static function walk( array $elements, array &$found ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['elType'], $el['widgetType'], $el['id'] ) && 'widget' === $el['elType'] && 'form' === $el['widgetType'] ) {
				$found[] = $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $found );
			}
		}
	}

	private static function describe( array $el, array $row, $key ) {
		$s = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

		// Campi del form; un form mai modificato non salva i campi e usa quelli di Elementor (nome, email, messaggio).
		$fields = array();
		$list   = ! empty( $s['form_fields'] ) && is_array( $s['form_fields'] ) ? $s['form_fields'] : array(
			array( 'custom_id' => 'name', 'field_label' => 'Name', 'field_type' => 'text' ),
			array( 'custom_id' => 'email', 'field_label' => 'Email', 'field_type' => 'email' ),
			array( 'custom_id' => 'message', 'field_label' => 'Message', 'field_type' => 'textarea' ),
		);
		foreach ( $list as $f ) {
			$type = isset( $f['field_type'] ) ? $f['field_type'] : 'text';
			if ( in_array( $type, array( 'html', 'step' ), true ) || empty( $f['custom_id'] ) ) {
				continue;
			}
			$fields[] = array(
				'id'    => sanitize_key( $f['custom_id'] ),
				'label' => isset( $f['field_label'] ) && '' !== $f['field_label'] ? wp_strip_all_tags( $f['field_label'] ) : $f['custom_id'],
				'type'  => $type,
			);
		}

		$type = 'elementor_library' === $row['post_type'] ? (string) get_post_meta( $row['ID'], '_elementor_template_type', true ) : $row['post_type'];

		return array(
			'key'        => $key,
			'post_id'    => (int) $row['ID'],
			'element_id' => (string) $el['id'],
			'name'       => isset( $s['form_name'] ) && '' !== $s['form_name'] ? wp_strip_all_tags( $s['form_name'] ) : 'New Form',
			'form_id'    => isset( $s['form_id'] ) ? sanitize_text_field( $s['form_id'] ) : '',
			'post_title' => '' !== $row['post_title'] ? $row['post_title'] : '#' . $row['ID'],
			'post_type'  => $type,
			'status'     => $row['post_status'],
			'fields'     => $fields,
			'actions'    => isset( $s['submit_actions'] ) && is_array( $s['submit_actions'] ) ? array_values( $s['submit_actions'] ) : array( 'email' ),
			// Valori impostati in Elementor: si mostrano come segnaposto quando l'override è vuoto.
			'elementor'  => array(
				'to'         => $s['email_to'] ?? '',
				'subject'    => $s['email_subject'] ?? '',
				'from_email' => $s['email_from'] ?? '',
				'from_name'  => $s['email_from_name'] ?? '',
				'reply_to'   => $s['email_reply_to'] ?? '',
				'cc'         => $s['email_to_cc'] ?? '',
				'bcc'        => $s['email_to_bcc'] ?? '',
				'redirect'   => $s['redirect_to'] ?? '',
			),
		);
	}

	/** Etichetta leggibile del tipo di contenuto. */
	public static function type_label( $type ) {
		$map = array(
			'page'    => __( 'Pagina', 'lu3g-form-manager' ),
			'post'    => __( 'Articolo', 'lu3g-form-manager' ),
			'popup'   => __( 'Popup', 'lu3g-form-manager' ),
			'header'  => __( 'Header', 'lu3g-form-manager' ),
			'footer'  => __( 'Footer', 'lu3g-form-manager' ),
			'section' => __( 'Template sezione', 'lu3g-form-manager' ),
			'widget'  => __( 'Widget globale', 'lu3g-form-manager' ),
			'single'  => __( 'Template singolo', 'lu3g-form-manager' ),
		);
		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}
		$obj = get_post_type_object( $type );
		return $obj ? $obj->labels->singular_name : ucfirst( (string) $type );
	}

	public static function edit_url( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' );
	}
}
