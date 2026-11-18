<?php
/**
 * Aggancio agli invii dei form di Elementor Pro (verificato sul codice di Elementor Pro 4.4, Ajax_Handler::ajax_send_form):
 *
 *   validazione → process_fields → filtro "elementor_pro/forms/record/actions_before"
 *   → filtro "elementor_pro/forms/submit_actions" (dalla 4.0.3) → azioni (email, email2, redirect, webhook...)
 *   → azione "elementor_pro/forms/new_record" → risposta al browser.
 *
 * 1. actions_before: si cambiano le impostazioni del form dentro il record ($record->set( 'form_settings', ... )), da cui
 *    l'azione Email di Elementor legge A, Oggetto, Da, Da nome, Rispondi a, Cc, Ccn. Un override vuoto lascia il valore
 *    di Elementor. Niente viene scritto in _elementor_data.
 * 2. submit_actions: con l'Email Admin LU3G si toglie "email", con l'Email Utente LU3G si toglie "email2" (niente doppioni).
 * 3. new_record: si salva la richiesta, parte il webhook, partono le email LU3G e, se c'è una thank you page, si mette
 *    nella risposta "redirect_url" (è proprio quello che fa l'azione Redirect di Elementor, quindi funziona anche se il
 *    form non ha l'azione Redirect, e vince su quella).
 * Qualunque errore qui viene catturato: il form del visitatore non deve mai fallire per colpa di questo plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Runtime {

	public static function init() {
		add_filter( 'elementor_pro/forms/record/actions_before', array( __CLASS__, 'apply_overrides' ), 10, 2 );
		add_filter( 'elementor_pro/forms/submit_actions', array( __CLASS__, 'filter_actions' ), 10, 3 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_record' ), 20, 2 );
	}

	/** Chiave del form nel record: "id post:id elemento" (per i template globali il post è il template). */
	public static function key_of( $record ) {
		$post = (int) $record->get_form_settings( 'form_post_id' );
		$id   = (string) $record->get_form_settings( 'id' );
		return $post . ':' . $id;
	}

	/** {{campo}} → [field id="campo"], la sintassi che Elementor sostituisce nelle sue impostazioni. */
	private static function to_elementor( $value ) {
		return preg_replace( '/\{\{([a-z0-9_\-]+)\}\}/i', '[field id="$1"]', (string) $value );
	}

	/**
	 * 1. Override delle impostazioni dell'Email di Elementor.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Record dell'invio.
	 */
	public static function apply_overrides( $record, $ajax_handler = null ) {
		try {
			$cfg = LU3G_FM_Settings::form( self::key_of( $record ) )['admin'];
			if ( 'lu3g' === $cfg['mode'] && LU3G_FM_Settings::can_replace_emails() ) {
				return $record; // l'Email di Elementor viene tolta in filter_actions: non serve toccarla
			}
			$settings = $record->get( 'form_settings' );
			if ( ! is_array( $settings ) ) {
				return $record;
			}
			$map = array(
				'to'         => 'email_to',
				'subject'    => 'email_subject',
				'from_email' => 'email_from',
				'from_name'  => 'email_from_name',
				'cc'         => 'email_to_cc',
				'bcc'        => 'email_to_bcc',
			);
			$changed = false;
			foreach ( $map as $ours => $theirs ) {
				if ( '' !== trim( (string) $cfg[ $ours ] ) ) {
					$settings[ $theirs ] = self::to_elementor( $cfg[ $ours ] );
					$changed             = true;
				}
			}
			// "Rispondi a" per Elementor è l'id di un campo email del form, non un indirizzo.
			$reply = preg_replace( '/^\{\{([a-z0-9_\-]+)\}\}$/i', '$1', trim( (string) $cfg['reply_to'] ) );
			if ( '' !== $reply && preg_match( '/^[a-z0-9_\-]+$/i', $reply ) ) {
				$settings['email_reply_to'] = $reply;
				$changed                    = true;
			}
			if ( $changed ) {
				$record->set( 'form_settings', $settings );
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
		}
		return $record;
	}

	/** 2. Toglie le email di Elementor sostituite da quelle LU3G. */
	public static function filter_actions( $actions, $record = null, $ajax_handler = null ) {
		if ( ! is_array( $actions ) || ! $record ) {
			return $actions;
		}
		try {
			$cfg = LU3G_FM_Settings::form( self::key_of( $record ) );
			if ( 'lu3g' === $cfg['admin']['mode'] ) {
				$actions = array_values( array_diff( $actions, array( 'email' ) ) );
			}
			if ( $cfg['user']['enabled'] ) {
				$actions = array_values( array_diff( $actions, array( 'email2' ) ) );
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
		}
		return $actions;
	}

	/** 3. Invio completato: richiesta, webhook, email LU3G, thank you page. */
	public static function on_record( $record, $ajax_handler ) {
		try {
			$key  = self::key_of( $record );
			$cfg  = LU3G_FM_Settings::form( $key );
			$form = self::form_info( $key, $record );
			$ctx  = self::context( $form, $record );

			$id = LU3G_FM_DB::insert_submission(
				array(
					'form_key'   => $key,
					'form_name'  => mb_substr( $form['name'], 0, 200 ),
					'post_id'    => (int) $form['post_id'],
					'page_url'   => $ctx['meta']['page_url'],
					'fields'     => wp_json_encode( $ctx['fields'], JSON_UNESCAPED_UNICODE ),
					'ip'         => mb_substr( $ctx['meta']['ip'], 0, 64 ),
					'user_agent' => mb_substr( $ctx['meta']['user_agent'], 0, 500 ),
					'created'    => current_time( 'mysql', true ),
				)
			);

			// Il webhook è quello di Elementor (registrato nel log da LU3G_FM_Webhook_Log): qui si collega la chiamata alla richiesta.
			LU3G_FM_Webhook_Log::attach_submission( $id );

			if ( 'lu3g' === $cfg['admin']['mode'] && LU3G_FM_Settings::can_replace_emails() ) {
				// Destinatari e mittente: quelli dell'Email di Elementor (modificabili nella scheda Impostazioni Elementor);
				// dal plugin arrivano oggetto e testo. I vecchi valori della 1.0/1.1, se ancora salvati, hanno la precedenza.
				$s     = (array) $record->get( 'form_settings' );
				$admin = $cfg['admin'];
				$map   = array( 'to' => 'email_to', 'from_email' => 'email_from', 'from_name' => 'email_from_name', 'reply_to' => 'email_reply_to', 'cc' => 'email_to_cc', 'bcc' => 'email_to_bcc' );
				foreach ( $map as $ours => $theirs ) {
					if ( '' === trim( (string) $admin[ $ours ] ) && ! empty( $s[ $theirs ] ) ) {
						$admin[ $ours ] = self::from_elementor( $s[ $theirs ] );
					}
				}
				if ( '' === trim( (string) $admin['subject'] ) && ! empty( $s['email_subject'] ) ) {
					$admin['subject'] = self::from_elementor( $s['email_subject'] );
				}
				if ( '' === trim( $admin['to'] ) ) {
					$admin['to'] = get_option( 'admin_email' );
				}
				$sent = LU3G_FM_Emails::send( $admin, $ctx, 'admin' );
				if ( is_wp_error( $sent ) && current_user_can( 'manage_options' ) ) {
					$ajax_handler->add_admin_error_message( 'LU3G Form Manager: ' . $sent->get_error_message() );
				}
			}

			if ( $cfg['user']['enabled'] && LU3G_FM_Settings::can_replace_emails() ) {
				$user       = $cfg['user'];
				$user['to'] = '' !== $user['to_field'] ? '{{' . $user['to_field'] . '}}' : self::first_email_field( $form );
				LU3G_FM_Emails::send( $user, $ctx, 'user' );
			}

			if ( '' !== $cfg['redirect'] ) {
				$url = preg_replace_callback(
					'/\{\{([a-z0-9_\-]+)\}\}/i',
					function ( $m ) use ( $ctx ) {
						return rawurlencode( LU3G_FM_Emails::replace_text( '{{' . $m[1] . '}}', $ctx ) );
					},
					$cfg['redirect']
				);
				if ( LU3G_FM_Settings::valid_url( $url ) || 0 === strpos( $url, '/' ) ) {
					$ajax_handler->add_response_data( 'redirect_url', esc_url_raw( $url ) );
				}
			}
		} catch ( \Throwable $e ) {
			self::log_error( $e );
		}
	}

	/** [field id="x"] di Elementor → {{x}}. */
	private static function from_elementor( $value ) {
		return preg_replace( '/\[field id="?([a-z0-9_\-]+)"?\]/i', '{{$1}}', (string) $value );
	}

	private static function first_email_field( array $form ) {
		foreach ( $form['fields'] as $f ) {
			if ( 'email' === $f['type'] ) {
				return '{{' . $f['id'] . '}}';
			}
		}
		return '';
	}

	/** Dati del form dalla scansione; se il form è nuovo e non ancora scansionato, dal record. */
	private static function form_info( $key, $record ) {
		$form = LU3G_FM_Scanner::form( $key );
		if ( $form ) {
			return $form;
		}
		$fields = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $f ) {
			$fields[] = array(
				'id'    => (string) ( $f['id'] ?? $id ),
				'label' => (string) ( $f['title'] ?? $id ),
				'type'  => (string) ( $f['type'] ?? 'text' ),
			);
		}
		list( $post_id, $element ) = array_pad( explode( ':', $key, 2 ), 2, '' );
		return array(
			'key'        => $key,
			'post_id'    => (int) $post_id,
			'element_id' => $element,
			'name'       => (string) ( $record->get_form_settings( 'form_name' ) ? $record->get_form_settings( 'form_name' ) : 'New Form' ),
			'form_id'    => (string) $record->get_form_settings( 'form_id' ),
			'fields'     => $fields,
			'elementor'  => array( 'to' => (string) $record->get_form_settings( 'email_to' ) ),
		);
	}

	/** Contesto dell'invio (campi con etichetta e valore, pagina, IP, user agent). */
	private static function context( array $form, $record ) {
		$fields = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $f ) {
			if ( in_array( $f['type'] ?? '', array( 'html', 'step', 'honeypot', 'recaptcha', 'recaptcha_v3' ), true ) ) {
				continue;
			}
			$fields[] = array(
				'id'    => (string) ( $f['id'] ?? $id ),
				'label' => (string) ( '' !== ( $f['title'] ?? '' ) ? $f['title'] : $id ),
				'type'  => (string) ( $f['type'] ?? 'text' ),
				'value' => $f['value'] ?? '',
			);
		}
		$meta = array();
		if ( method_exists( $record, 'get_form_meta' ) ) {
			foreach ( (array) $record->get_form_meta( array( 'page_url', 'page_title', 'remote_ip', 'user_agent' ) ) as $k => $m ) {
				$meta[ $k ] = is_array( $m ) ? (string) ( $m['value'] ?? '' ) : (string) $m;
			}
		}
		return LU3G_FM_Emails::context(
			$form,
			$fields,
			array(
				'page_url'   => $meta['page_url'] ?? ( isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- dentro l'invio di Elementor, già verificato
				'page_title' => $meta['page_title'] ?? '',
				'ip'         => $meta['remote_ip'] ?? '',
				'user_agent' => $meta['user_agent'] ?? '',
				'time'       => time(),
			)
		);
	}

	private static function log_error( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'LU3G Form Manager: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
