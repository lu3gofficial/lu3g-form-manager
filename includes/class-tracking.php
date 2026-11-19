<?php
/**
 * Tracciamento per Google Tag Manager (1.5.0).
 *
 * Il plugin non manda niente a Google o Meta: mette nel dataLayer un evento pulito e uguale su tutti i siti, e GTM
 * decide dove mandarlo (GA4, Ads, Meta, server-side...). Tutto è spento finché non lo si accende nelle Impostazioni.
 *
 * - Codice head/body: facoltativo, per incollare lo snippet di GTM del sito così com'è (standard o server-side).
 * - Evento dei form (assets/tracking.js): dopo un invio riuscito (evento "submit_success" di Elementor Pro)
 *   { event, event_id, form_name, form_id, page_url, page_title, site_domain, email, phone, user_data }.
 *   I valori si leggono al momento dell'invio (prima che Elementor svuoti il form).
 * - event_id condiviso: lo script lo genera all'invio e lo aggiunge al form (campo nascosto "lu3g_event_id"); il
 *   server lo salva nella richiesta e lo aggiunge ai dati del webhook di Elementor. Così CRM e Meta CAPI ricevono lo
 *   stesso ID dell'evento nel browser e possono deduplicare.
 * - Click su WhatsApp, telefono, email: evento "contact_click".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Tracking {

	const FIELD = 'lu3g_event_id';

	/** event_id dell'invio in corso (null = non ancora letto). */
	private static $event_id = null;

	public static function init() {
		$g = LU3G_FM_Settings::general();
		if ( '' !== trim( $g['trk_head'] ) ) {
			add_action( 'wp_head', array( __CLASS__, 'head_code' ), 1 );
		}
		if ( '' !== trim( $g['trk_body'] ) ) {
			add_action( 'wp_body_open', array( __CLASS__, 'body_code' ), 1 );
		}
		if ( $g['trk_enabled'] ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 5 );
			if ( $g['trk_server'] ) {
				// Prima del log del webhook (9999), così il log mostra i dati con l'event_id.
				add_filter( 'elementor_pro/forms/webhooks/request_args', array( __CLASS__, 'webhook_args' ), 20, 2 );
			}
		}
	}

	/** Pagine dove non si traccia: anteprima dell'editor di Elementor, Customizer. */
	private static function skip() {
		if ( is_admin() || is_feed() || is_customize_preview() ) {
			return true;
		}
		if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode();
	}

	public static function head_code() {
		if ( ! self::skip() ) {
			echo "\n<!-- LU3G Form Manager: codice head -->\n" . LU3G_FM_Settings::general()['trk_head'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- codice inserito da un amministratore con unfiltered_html
		}
	}

	public static function body_code() {
		if ( ! self::skip() ) {
			echo "\n<!-- LU3G Form Manager: codice body -->\n" . LU3G_FM_Settings::general()['trk_body'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- codice inserito da un amministratore con unfiltered_html
		}
	}

	public static function enqueue() {
		if ( self::skip() ) {
			return;
		}
		wp_enqueue_script( 'lu3g-fm-tracking', LU3G_FM_URL . 'assets/tracking.js', array(), LU3G_FM_VERSION, false );
		wp_add_inline_script( 'lu3g-fm-tracking', 'window.lu3gFmTrack=' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/** Configurazione per lo script: impostazioni generali e, per ogni form, nome dell'evento e campi. */
	public static function config() {
		$g     = LU3G_FM_Settings::general();
		$forms = array();
		foreach ( LU3G_FM_Scanner::forms() as $key => $f ) {
			$t   = LU3G_FM_Settings::form( $key )['trk'];
			$row = array( 'n' => (string) $f['name'] );
			if ( ! $t['enabled'] ) {
				$row['off'] = 1;
			}
			if ( '' !== $t['event'] ) {
				$row['e'] = $t['event'];
			}
			$fields = self::fields( $f, $t );
			if ( '' !== $fields['email'] ) {
				$row['m'] = $fields['email'];
			}
			if ( '' !== $fields['phone'] ) {
				$row['p'] = $fields['phone'];
			}
			$forms[ $key ] = $row;
		}
		return array(
			'event'        => $g['trk_event'],
			'pii'          => $g['trk_pii'],
			'cc'           => $g['trk_country'],
			'server'       => $g['trk_server'],
			'contacts'     => $g['trk_contacts'],
			'contactEvent' => $g['trk_contact_event'],
			'debug'        => $g['trk_debug'],
			'forms'        => $forms,
		);
	}

	/**
	 * Campi email e telefono di un form: quelli scelti nella scheda, altrimenti il primo campo di tipo email / tel,
	 * altrimenti un campo con un id che sembra un telefono.
	 *
	 * @return array [ 'email' => id|'' , 'phone' => id|'' , 'email_auto' => bool, 'phone_auto' => bool ]
	 */
	public static function fields( array $form, array $trk ) {
		$email = '';
		$phone = '';
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			if ( '' === $email && 'email' === $f['type'] ) {
				$email = (string) $f['id'];
			}
			if ( '' === $phone && 'tel' === $f['type'] ) {
				$phone = (string) $f['id'];
			}
		}
		if ( '' === $phone ) {
			foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
				if ( preg_match( '/^(tel|telefono|phone|cell|cellulare|mobile|numero_telefono|numero)$/i', (string) $f['id'] ) ) {
					$phone = (string) $f['id'];
					break;
				}
			}
		}
		return array(
			'email'      => '' !== $trk['email'] ? $trk['email'] : $email,
			'phone'      => '' !== $trk['phone'] ? $trk['phone'] : $phone,
			'email_auto' => '' === $trk['email'],
			'phone_auto' => '' === $trk['phone'],
		);
	}

	/**
	 * event_id dell'invio in corso: quello arrivato dal browser; se manca (script bloccato o non caricato) se ne crea
	 * uno sul server, così richiesta e webhook ne hanno comunque uno. '' se il tracciamento lato server è spento.
	 */
	public static function event_id() {
		if ( null !== self::$event_id ) {
			return self::$event_id;
		}
		self::$event_id = '';
		$g              = LU3G_FM_Settings::general();
		if ( ! $g['trk_enabled'] || ! $g['trk_server'] || ! wp_doing_ajax() ) {
			return '';
		}
		$id = isset( $_POST[ self::FIELD ] ) ? preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- dentro l'invio di Elementor; ripulito qui
		$id = substr( (string) $id, 0, 64 );
		if ( '' === $id ) {
			$id = round( microtime( true ) * 1000 ) . '_' . strtolower( wp_generate_password( 9, false, false ) );
		}
		self::$event_id = $id;
		return $id;
	}

	/** Il form ha il tracciamento acceso? */
	public static function form_on( $key ) {
		$g = LU3G_FM_Settings::general();
		return $g['trk_enabled'] && LU3G_FM_Settings::form( $key )['trk']['enabled'];
	}

	/** event_id per l'invio di questo form ('' se il form non è tracciato). */
	public static function event_id_for( $key ) {
		return self::form_on( $key ) ? self::event_id() : '';
	}

	/** Aggiunge event_id ai dati del webhook di Elementor (solo se il form è tracciato). */
	public static function webhook_args( $args, $record ) {
		try {
			$id = self::event_id_for( LU3G_FM_Runtime::key_of( $record ) );
			if ( '' !== $id && isset( $args['body'] ) && is_array( $args['body'] ) && ! isset( $args['body']['event_id'] ) ) {
				$args['body']['event_id'] = $id;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $args;
	}

	/**
	 * Controllo della pagina iniziale: GTM caricato quante volte, altri strumenti, script dei form già presenti.
	 *
	 * @return array|WP_Error [ [ 'level' => ok|warn|info, 'text' => ... ] ]
	 */
	public static function check() {
		$url = add_query_arg( 'lu3g_fm_check', time(), home_url( '/' ) );
		$res = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$html = (string) wp_remote_retrieve_body( $res );
		if ( '' === $html ) {
			/* translators: %d: codice HTTP */
			return new WP_Error( 'lu3g_fm_check', sprintf( __( 'La pagina iniziale non ha risposto (HTTP %d).', 'lu3g-form-manager' ), (int) wp_remote_retrieve_response_code( $res ) ) );
		}
		$g   = LU3G_FM_Settings::general();
		$out = array();

		$gtm = substr_count( $html, 'gtm.start' );
		preg_match_all( '/GTM-[A-Z0-9]{4,10}/', $html, $ids );
		$ids = array_values( array_unique( $ids[0] ) );
		if ( 0 === $gtm ) {
			$out[] = array( 'level' => 'warn', 'text' => __( 'Google Tag Manager non risulta caricato: gli eventi finiscono nel dataLayer ma nessuno li legge. Incolla lo snippet del sito qui sopra o installalo nel tema / in WPCode.', 'lu3g-form-manager' ) );
		} elseif ( 1 === $gtm ) {
			/* translators: %s: ID del container */
			$out[] = array( 'level' => 'ok', 'text' => sprintf( __( 'Google Tag Manager caricato una volta%s.', 'lu3g-form-manager' ), $ids ? ' (' . implode( ', ', $ids ) . ')' : '' ) );
		} else {
			/* translators: 1: numero, 2: ID */
			$out[] = array( 'level' => 'warn', 'text' => sprintf( __( 'Google Tag Manager è caricato %1$d volte%2$s: se è lo stesso container, ogni evento conta doppio. Lascia un solo snippet (tema, WPCode o qui).', 'lu3g-form-manager' ), $gtm, $ids ? ' (' . implode( ', ', $ids ) . ')' : '' ) );
		}
		if ( '' !== trim( $g['trk_head'] ) && $gtm > 0 && false !== strpos( $html, 'LU3G Form Manager: codice head' ) && $gtm > substr_count( $g['trk_head'], 'gtm.start' ) ) {
			$out[] = array( 'level' => 'warn', 'text' => __( 'Il codice head di questo plugin carica GTM, ma GTM c\'è anche da un\'altra parte (tema, WPCode, altro plugin).', 'lu3g-form-manager' ) );
		}
		if ( false !== strpos( $html, 'googletagmanager.com/gtag/js' ) ) {
			$out[] = array( 'level' => 'info', 'text' => __( 'C\'è anche gtag.js (GA4 o Google Ads installati direttamente, fuori da GTM): controlla che le conversioni non si contino due volte.', 'lu3g-form-manager' ) );
		}
		if ( false !== strpos( $html, 'connect.facebook.net' ) ) {
			$out[] = array( 'level' => 'info', 'text' => __( 'C\'è il Meta Pixel caricato direttamente nella pagina.', 'lu3g-form-manager' ) );
		}

		// Script scritti a mano che ascoltano gli invii di Elementor: con il tracciamento attivo i lead conterebbero doppio.
		$custom = 0;
		if ( preg_match_all( '#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $js ) {
				if ( false !== strpos( $js, 'submit_success' ) && false === strpos( $js, 'lu3gFmTrack' ) ) {
					$custom++;
				}
			}
		}
		if ( $custom ) {
			$out[] = array(
				'level' => $g['trk_enabled'] ? 'warn' : 'info',
				'text'  => $g['trk_enabled']
					? __( 'Nella pagina c\'è anche uno script scritto a mano che ascolta gli invii dei form (submit_success): toglilo, altrimenti ogni lead viene contato due volte.', 'lu3g-form-manager' )
					: __( 'Nella pagina c\'è uno script scritto a mano che ascolta gli invii dei form (submit_success). Quando accendi il tracciamento del plugin, toglilo.', 'lu3g-form-manager' ),
			);
		}

		if ( $g['trk_enabled'] ) {
			$out[] = false !== strpos( $html, 'lu3g-fm-tracking' )
				? array( 'level' => 'ok', 'text' => __( 'Lo script del tracciamento del plugin è nella pagina.', 'lu3g-form-manager' ) )
				: array( 'level' => 'warn', 'text' => __( 'Lo script del tracciamento non è nella pagina: forse la cache della pagina non si è ancora aggiornata (svuotala) o un plugin di ottimizzazione lo toglie.', 'lu3g-form-manager' ) );
		}
		if ( '' !== trim( $g['trk_body'] ) && false === strpos( $html, 'LU3G Form Manager: codice body' ) ) {
			$out[] = array( 'level' => 'info', 'text' => __( 'Il codice body non compare: il tema non chiama wp_body_open(). Di solito è solo il noscript di GTM, quindi non è grave.', 'lu3g-form-manager' ) );
		}
		return $out;
	}
}
