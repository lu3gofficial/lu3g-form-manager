<?php
/**
 * Conversioni (1.3.0): a ogni invio riuscito di un form partono gli eventi verso gli strumenti già presenti nel sito.
 *
 * - Google Tag Manager: dataLayer.push( { event: "lu3g_form_submit", lu3g_form_name, lu3g_form_key, … } ).
 * - Google Analytics 4: gtag( 'event', 'generate_lead', { form_name, form_id } ), se gtag c'è.
 * - Google Ads: gtag( 'event', 'conversion', { send_to, value, currency } ), se gtag c'è.
 * - Meta Pixel: fbq( 'track', 'Lead', { content_name } ) (o trackCustom per un evento non standard), se fbq c'è.
 * Gli strumenti non vengono caricati da qui: si usano quelli che il sito ha già (tema, GTM, plugin dei cookie). Il
 * consenso resta di chi li carica: se un utente rifiuta i cookie e lo strumento non c'è, l'evento non parte.
 *
 * L'evento si ascolta su "submit_success", che Elementor Pro emette sul form quando l'invio è andato a buon fine.
 * Subito dopo Elementor cambia pagina verso la thank you page, e gli eventi rischierebbero di perdersi: sui form con
 * conversioni attive il redirect viene tolto dalla risposta di Elementor e lo fa lo script, 700 ms dopo gli eventi.
 * Nessun dato personale del form (nomi, email…) viene inviato agli strumenti: solo nome e id del form e la pagina.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Conversions {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'hold_redirect' ), 50, 2 );
	}

	/** Configurazione per lo script: [ forms => [ chiave => config|false ], defaults => config|false ]. */
	public static function config() {
		$forms = array();
		$any   = false;
		foreach ( LU3G_FM_Scanner::forms() as $key => $form ) {
			$c             = LU3G_FM_Settings::conversions_for( $key );
			$forms[ $key ] = $c ? $c : false;
			$any           = $any || (bool) $c;
		}
		$g        = LU3G_FM_Settings::general();
		$defaults = false;
		if ( $g['conv_enabled'] ) {
			// Per i form non ancora scansionati: le impostazioni generali.
			$defaults = LU3G_FM_Settings::conversions_for( '__nessun_form__' );
			$any      = $any || (bool) $defaults;
		}
		return $any ? array( 'forms' => $forms, 'defaults' => $defaults ? $defaults : false ) : null;
	}

	public static function enqueue() {
		$cfg = self::config();
		if ( ! $cfg ) {
			return;
		}
		wp_enqueue_script( 'lu3g-fm-conversions', LU3G_FM_URL . 'assets/conversions.js', array( 'jquery' ), LU3G_FM_VERSION, true );
		wp_localize_script( 'lu3g-fm-conversions', 'LU3G_FM_CONV', $cfg );
	}

	/**
	 * Sposta il redirect di Elementor in "lu3g_redirect_url": così il browser non cambia pagina prima degli eventi.
	 * La risposta di Elementor non ha un metodo per togliere un dato, quindi si usa una closure legata all'oggetto.
	 */
	public static function hold_redirect( $record, $ajax_handler ) {
		try {
			if ( ! LU3G_FM_Settings::conversions_for( LU3G_FM_Runtime::key_of( $record ) ) ) {
				return;
			}
			$take = function () {
				if ( ! isset( $this->data['redirect_url'] ) ) {
					return '';
				}
				$url = $this->data['redirect_url'];
				unset( $this->data['redirect_url'] );
				return $url;
			};
			$url = \Closure::bind( $take, $ajax_handler, get_class( $ajax_handler ) )();
			if ( is_string( $url ) && '' !== $url ) {
				$ajax_handler->add_response_data( 'lu3g_redirect_url', $url );
			}
		} catch ( \Throwable $e ) {
			// Se Elementor cambia struttura, il redirect resta il suo: meglio una conversione persa che un form rotto.
			unset( $e );
		}
	}
}
