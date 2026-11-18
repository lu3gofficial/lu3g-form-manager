/**
 * LU3G Form Manager — conversioni.
 * Su "submit_success" (evento jQuery che Elementor Pro emette sul form riuscito) invia gli eventi a GTM, GA4,
 * Google Ads e Meta Pixel, se presenti nel sito, poi fa il redirect alla thank you page (che il server ha
 * spostato in lu3g_redirect_url proprio per non perdere gli eventi).
 * Per controllare cosa parte: nella console del browser scrivi  LU3G_FM_CONV.debug = true  e invia il form.
 * Le integrazioni personalizzate possono ascoltare l'evento DOM "lu3g:form-conversion" sul form.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.LU3G_FM_CONV;
	if ( ! cfg || ! $ ) {
		return;
	}
	var META_STANDARD = [ 'Lead', 'Contact', 'CompleteRegistration', 'SubmitApplication', 'Schedule', 'Subscribe', 'StartTrial', 'FindLocation', 'CustomizeProduct', 'Donate' ];

	function log() {
		if ( cfg.debug && window.console ) {
			window.console.log.apply( window.console, [ '[LU3G conversioni]' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}

	function keyOf( form ) {
		var post = form.querySelector( '[name="post_id"]' );
		var id = form.querySelector( '[name="form_id"]' );
		return post && id ? post.value + ':' + id.value : '';
	}

	function fire( conv, info ) {
		if ( conv.gtm ) {
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push( {
				event: conv.gtm,
				lu3g_form_name: info.name,
				lu3g_form_key: info.key,
				lu3g_page: window.location.href
			} );
			log( 'GTM dataLayer', conv.gtm );
		}
		if ( 'function' === typeof window.gtag ) {
			if ( conv.ga4 ) {
				window.gtag( 'event', conv.ga4, { form_name: info.name, form_id: info.key, transport_type: 'beacon' } );
				log( 'GA4', conv.ga4 );
			}
			if ( conv.ads ) {
				var params = { send_to: conv.ads.send_to, transport_type: 'beacon' };
				if ( null !== conv.ads.value && undefined !== conv.ads.value ) {
					params.value = conv.ads.value;
					params.currency = conv.ads.currency || 'EUR';
				}
				window.gtag( 'event', 'conversion', params );
				log( 'Google Ads', params );
			}
		} else if ( conv.ga4 || conv.ads ) {
			log( 'gtag non presente: GA4/Google Ads saltati (se usi GTM, crea i tag sull\'evento ' + conv.gtm + ')' );
		}
		if ( conv.meta ) {
			if ( 'function' === typeof window.fbq ) {
				window.fbq( META_STANDARD.indexOf( conv.meta ) !== -1 ? 'track' : 'trackCustom', conv.meta, { content_name: info.name } );
				log( 'Meta', conv.meta );
			} else {
				log( 'fbq non presente: Meta saltato' );
			}
		}
	}

	$( document ).on( 'submit_success', function ( event, response ) {
		var form = event.target;
		if ( ! form || 'FORM' !== form.tagName ) {
			return;
		}
		var key = keyOf( form );
		var conv = Object.prototype.hasOwnProperty.call( cfg.forms, key ) ? cfg.forms[ key ] : cfg.defaults;
		var redirect = response && response.data && response.data.lu3g_redirect_url;
		var info = { key: key, name: form.getAttribute( 'name' ) || key };

		if ( conv ) {
			try {
				fire( conv, info );
			} catch ( e ) {
				log( 'errore', e );
			}
			try {
				form.dispatchEvent( new window.CustomEvent( 'lu3g:form-conversion', { bubbles: true, detail: { key: key, name: info.name, config: conv } } ) );
			} catch ( e ) {}
		}
		if ( redirect ) {
			// Un attimo per far partire gli eventi, poi la thank you page.
			window.setTimeout( function () {
				window.location.href = redirect;
			}, 700 );
		}
	} );
}( window.jQuery ) );
