<?php
/**
 * Lettura e modifica delle impostazioni del form direttamente in Elementor (1.2.0).
 *
 * - Si legge _elementor_data del post (pagina, template, popup) e si trova il widget form per id.
 * - Elementor salva solo i valori diversi dal predefinito: dove manca un valore si mostra quello predefinito.
 * - Il salvataggio passa da Elementor stesso: Document::save( [ 'elements' => ... ] ) scrive _elementor_data, rigenera
 *   il testo per la ricerca, cancella il CSS e la cache della pagina, come quando si salva dall'editor. Se Elementor
 *   non offre il documento (caso raro), si scrive il meta e si cancellano CSS e cache a mano.
 * - Prima di ogni modifica si tiene una copia delle impostazioni del form (meta "_lu3g_fm_backup" del post, una per
 *   form): "Annulla l'ultima modifica" le rimette com'erano.
 * - Un campo con un tag dinamico di Elementor (__dynamic__) resta dinamico finché non lo si cambia qui; cambiandolo,
 *   il tag viene tolto e vale il valore scritto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Elementor_Editor {

	const BACKUP = '_lu3g_fm_backup';

	/** Impostazioni modificabili: chiave Elementor => [ tipo, predefinito ]. */
	public static function fields() {
		$site  = get_bloginfo( 'name' );
		$admin = get_option( 'admin_email' );
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$from  = 'email@' . preg_replace( '/^www\./', '', (string) $host );
		$subj  = sprintf( 'New message from "%s"', $site );
		$meta  = array( 'date', 'time', 'page_url', 'user_agent', 'remote_ip', 'credit' );
		return array(
			'form_name'              => array( 'text', 'New Form' ),
			'submit_actions'         => array( 'list', array( 'email' ) ),
			'email_to'               => array( 'text', $admin ),
			'email_subject'          => array( 'text', $subj ),
			'email_content'          => array( 'textarea', '[all-fields]' ),
			'email_from'             => array( 'text', $from ),
			'email_from_name'        => array( 'text', $site ),
			'email_reply_to'         => array( 'text', '' ),
			'email_to_cc'            => array( 'text', '' ),
			'email_to_bcc'           => array( 'text', '' ),
			'form_metadata'          => array( 'list', $meta ),
			'email_content_type'     => array( 'text', 'html' ),
			'email_to_2'             => array( 'text', '' ),
			'email_subject_2'        => array( 'text', $subj ),
			'email_content_2'        => array( 'textarea', '[all-fields]' ),
			'email_from_2'           => array( 'text', $from ),
			'email_from_name_2'      => array( 'text', $site ),
			'email_reply_to_2'       => array( 'text', '' ),
			'email_to_cc_2'          => array( 'text', '' ),
			'email_to_bcc_2'         => array( 'text', '' ),
			'form_metadata_2'        => array( 'list', array() ),
			'email_content_type_2'   => array( 'text', 'html' ),
			'redirect_to'            => array( 'text', '' ),
			'webhooks'               => array( 'text', '' ),
			'webhooks_advanced_data' => array( 'text', '' ),
		);
	}

	/** Azioni dopo l'invio gestite qui (le altre, es. Mailchimp, restano come sono). */
	public static function actions() {
		return array(
			'email'    => __( 'Email', 'lu3g-form-manager' ),
			'email2'   => __( 'Email 2', 'lu3g-form-manager' ),
			'redirect' => __( 'Redirect (thank you page)', 'lu3g-form-manager' ),
			'webhook'  => __( 'Webhook', 'lu3g-form-manager' ),
		);
	}

	/** Dati Elementor del post (array di elementi) o null. */
	private static function data( $post_id ) {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/** Trova il widget per id e lo passa per riferimento alla callback. */
	private static function find( array &$elements, $id, callable $cb ) {
		foreach ( $elements as &$el ) {
			if ( is_array( $el ) && isset( $el['id'] ) && $el['id'] === $id && 'form' === ( $el['widgetType'] ?? '' ) ) {
				$cb( $el );
				return true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) && self::find( $el['elements'], $id, $cb ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Impostazioni attuali del form: per ogni campo il valore effettivo, se è salvato o predefinito, se è dinamico.
	 *
	 * @return array|null [ 'values' => [], 'saved' => [ chiave => bool ], 'dynamic' => [ chiave => bool ], 'other_actions' => [] ]
	 */
	public static function get( $post_id, $element_id ) {
		$data = self::data( $post_id );
		if ( null === $data ) {
			return null;
		}
		$settings = null;
		self::find(
			$data,
			$element_id,
			function ( &$el ) use ( &$settings ) {
				$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			}
		);
		if ( null === $settings ) {
			return null;
		}
		$out = array( 'values' => array(), 'saved' => array(), 'dynamic' => array() );
		foreach ( self::fields() as $key => $def ) {
			$has                    = array_key_exists( $key, $settings );
			$out['values'][ $key ]  = $has ? $settings[ $key ] : $def[1];
			$out['saved'][ $key ]   = $has;
			$out['dynamic'][ $key ] = ! empty( $settings['__dynamic__'][ $key ] );
		}
		$out['other_actions'] = array_values( array_diff( (array) $out['values']['submit_actions'], array_keys( self::actions() ) ) );
		return $out;
	}

	/**
	 * Applica le modifiche al form e salva con Elementor.
	 *
	 * @param array $changes chiave => valore (solo i campi di fields()). Un testo vuoto toglie il valore (torna il predefinito).
	 * @return true|WP_Error
	 */
	public static function save( $post_id, $element_id, array $changes ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'lu3g_fm_cap', __( 'Non hai i permessi per modificare questa pagina.', 'lu3g-form-manager' ) );
		}
		$data = self::data( $post_id );
		if ( null === $data ) {
			return new WP_Error( 'lu3g_fm_nodata', __( 'Questa pagina non ha dati di Elementor leggibili.', 'lu3g-form-manager' ) );
		}
		$fields = self::fields();
		$backup = null;
		$found  = self::find(
			$data,
			$element_id,
			function ( &$el ) use ( $changes, $fields, &$backup ) {
				$s      = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
				$backup = $s;
				foreach ( $changes as $key => $value ) {
					if ( ! isset( $fields[ $key ] ) ) {
						continue;
					}
					$value = self::clean( $key, $value, $fields[ $key ][0] );
					// Un campo cambiato qui non è più dinamico.
					if ( isset( $s['__dynamic__'][ $key ] ) && ( ! array_key_exists( $key, $s ) || $s[ $key ] !== $value ) ) {
						unset( $s['__dynamic__'][ $key ] );
					}
					if ( '' === $value || ( is_array( $value ) && $value === $fields[ $key ][1] && 'submit_actions' !== $key ) ) {
						unset( $s[ $key ] );
					} else {
						$s[ $key ] = $value;
					}
				}
				if ( isset( $s['__dynamic__'] ) && ! $s['__dynamic__'] ) {
					unset( $s['__dynamic__'] );
				}
				$el['settings'] = $s;
			}
		);
		if ( ! $found ) {
			return new WP_Error( 'lu3g_fm_noform', __( 'Il form non c\'è più in questa pagina: riscansiona.', 'lu3g-form-manager' ) );
		}

		// Copia di prima, per "Annulla l'ultima modifica".
		$all                = get_post_meta( $post_id, self::BACKUP, true );
		$all                = is_array( $all ) ? $all : array();
		$all[ $element_id ] = array(
			'time'     => time(),
			'user'     => get_current_user_id(),
			'settings' => $backup,
		);
		update_post_meta( $post_id, self::BACKUP, $all );

		return self::write( $post_id, $data );
	}

	/** Rimette le impostazioni del form com'erano prima dell'ultima modifica. */
	public static function undo( $post_id, $element_id ) {
		$all = get_post_meta( $post_id, self::BACKUP, true );
		if ( ! is_array( $all ) || ! isset( $all[ $element_id ] ) ) {
			return new WP_Error( 'lu3g_fm_nobackup', __( 'Non c\'è una modifica da annullare.', 'lu3g-form-manager' ) );
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return new WP_Error( 'lu3g_fm_cap', __( 'Non hai i permessi per modificare questa pagina.', 'lu3g-form-manager' ) );
		}
		$data     = self::data( $post_id );
		$previous = $all[ $element_id ]['settings'];
		$found    = null !== $data && self::find(
			$data,
			$element_id,
			function ( &$el ) use ( $previous ) {
				$el['settings'] = $previous;
			}
		);
		if ( ! $found ) {
			return new WP_Error( 'lu3g_fm_noform', __( 'Il form non c\'è più in questa pagina.', 'lu3g-form-manager' ) );
		}
		unset( $all[ $element_id ] );
		update_post_meta( $post_id, self::BACKUP, $all );
		return self::write( $post_id, $data );
	}

	public static function backup_info( $post_id, $element_id ) {
		$all = get_post_meta( $post_id, self::BACKUP, true );
		return is_array( $all ) && isset( $all[ $element_id ] ) ? $all[ $element_id ] : null;
	}

	private static function write( $post_id, array $data ) {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$doc = \Elementor\Plugin::$instance->documents->get( $post_id, false );
			if ( $doc ) {
				$ok = $doc->save( array( 'elements' => $data ) );
				if ( false === $ok ) {
					return new WP_Error( 'lu3g_fm_save', __( 'Elementor non ha salvato la pagina (permessi?).', 'lu3g-form-manager' ) );
				}
				return true;
			}
		}
		// Ripiego: si scrive il meta come fa Elementor e si cancellano CSS e cache della pagina.
		update_metadata( 'post', $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		return true;
	}

	/** Valori sanificati per tipo; le liste accettano solo voci note. */
	private static function clean( $key, $value, $type ) {
		if ( 'list' === $type ) {
			$value = array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );
			return $value;
		}
		if ( 'textarea' === $type ) {
			return wp_kses_post( (string) $value );
		}
		if ( 'webhooks_advanced_data' === $key ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'yes' : '';
		}
		if ( in_array( $key, array( 'email_content_type', 'email_content_type_2' ), true ) ) {
			return 'plain' === $value ? 'plain' : 'html';
		}
		if ( in_array( $key, array( 'redirect_to', 'webhooks' ), true ) ) {
			// Si tengono gli shortcode [field id="..."] di Elementor (esc_url_raw li toglierebbe).
			$keep  = array();
			$value = preg_replace_callback(
				'/\[field id="?[a-z0-9_\-]+"?\]/i',
				function ( $m ) use ( &$keep ) {
					$t          = 'lu3gsc' . count( $keep ) . 'x';
					$keep[ $t ] = $m[0];
					return $t;
				},
				trim( (string) $value )
			);
			return strtr( esc_url_raw( $value ), $keep );
		}
		return sanitize_text_field( (string) $value );
	}
}
