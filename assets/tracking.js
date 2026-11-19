/**
 * LU3G Form Manager: eventi dei form di Elementor nel dataLayer (per Google Tag Manager).
 *
 * - All'invio (fase di cattura, prima del gestore di Elementor) legge email e telefono, crea l'event_id e lo
 *   aggiunge al form come campo nascosto: così arriva anche al server (richiesta salvata, webhook).
 * - Dopo l'invio riuscito ("submit_success" di Elementor, evento jQuery) mette l'evento nel dataLayer.
 * - Click su WhatsApp / telefono / email: evento dei contatti.
 * Non dipende da jQuery per partire: se jQuery arriva dopo (es. WP Rocket "ritarda JS") l'ascolto si aggancia appena c'è.
 * Debug: impostazione "Modalità debug" oppure ?lu3g_debug=1 nell'indirizzo.
 */
( function () {
	'use strict';

	var C = window.lu3gFmTrack;
	if ( ! C || window.__lu3gFmTrackOn ) {
		return;
	}
	window.__lu3gFmTrackOn = true;

	var debug = !! C.debug || /[?&]lu3g_debug=1/.test( location.search );
	var FIELD = 'lu3g_event_id';

	function log( label, data ) {
		if ( debug && window.console ) {
			console.log( '%cLU3G Form%c ' + label, 'background:#09090b;color:#fff;padding:1px 6px;border-radius:3px', '', data );
		}
	}

	function newId() {
		return Date.now() + '_' + Math.random().toString( 36 ).slice( 2, 11 );
	}

	function push( obj ) {
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( obj );
		log( obj.event, obj );
	}

	/* ---------------------------------------------------------------- normalizzazione */

	function normEmail( v ) {
		v = String( v || '' ).trim().toLowerCase();
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v ) ? v : '';
	}

	/**
	 * Telefono in formato internazionale (+39...).
	 * "+41 79..." resta +4179...; "0039 333..." → +39333...; un numero senza prefisso riceve quello impostato.
	 * Un numero che inizia già col prefisso si considera completo solo se, tolto il prefisso, restano almeno 9 cifre
	 * e il totale è almeno 11: così un cellulare "393 123 4567" (10 cifre) diventa +393931234567, non +3931234567.
	 */
	function normPhone( v ) {
		v = String( v || '' ).trim();
		if ( ! v ) {
			return '';
		}
		var plus = v.charAt( 0 ) === '+';
		var d = v.replace( /\D/g, '' );
		var cc = String( C.cc || '39' );
		if ( d.length < 6 ) {
			return '';
		}
		if ( plus ) {
			return '+' + d;
		}
		if ( d.indexOf( '00' ) === 0 ) {
			return '+' + d.slice( 2 );
		}
		if ( d.indexOf( cc ) === 0 && d.length - cc.length >= 9 && d.length >= 11 ) {
			return '+' + d;
		}
		return '+' + cc + d;
	}

	/* ---------------------------------------------------------------- form */

	function cfgOf( form ) {
		var p = form.querySelector( 'input[name="post_id"]' );
		var f = form.querySelector( 'input[name="form_id"]' );
		var key = ( p ? p.value : '' ) + ':' + ( f ? f.value : '' );
		return { key: key, c: ( C.forms && C.forms[ key ] ) || {} };
	}

	function valueOf( form, id ) {
		if ( ! id ) {
			return '';
		}
		var el = form.querySelector( '[name="form_fields[' + id + ']"]' );
		return el && el.value ? el.value : '';
	}

	function firstOfType( form, type ) {
		var el = form.querySelector( 'input[type="' + type + '"][name^="form_fields["]' );
		return el && el.value ? el.value : '';
	}

	var PHONE_IDS = [ 'telefono', 'phone', 'tel', 'cellulare', 'cell', 'mobile', 'numero_telefono' ];

	function emailOf( form, c ) {
		return c.m ? valueOf( form, c.m ) : ( firstOfType( form, 'email' ) || valueOf( form, 'email' ) );
	}

	function phoneOf( form, c ) {
		if ( c.p ) {
			return valueOf( form, c.p );
		}
		var v = firstOfType( form, 'tel' );
		for ( var i = 0; ! v && i < PHONE_IDS.length; i++ ) {
			v = valueOf( form, PHONE_IDS[ i ] );
		}
		return v;
	}

	/** Fotografia dell'invio: id, email e telefono letti prima che Elementor svuoti il form. */
	function capture( form ) {
		var x = cfgOf( form );
		if ( x.c.off ) {
			form.__lu3gFm = null;
			return null;
		}
		var snap = {
			id: newId(),
			key: x.key,
			c: x.c,
			email: normEmail( emailOf( form, x.c ) ),
			phone: normPhone( phoneOf( form, x.c ) ),
			sent: false
		};
		form.__lu3gFm = snap;
		if ( C.server ) {
			var h = form.querySelector( 'input[name="' + FIELD + '"]' );
			if ( ! h ) {
				h = document.createElement( 'input' );
				h.type = 'hidden';
				h.name = FIELD;
				form.appendChild( h );
			}
			h.value = snap.id;
		}
		return snap;
	}

	function isElementorForm( el ) {
		return el && el.tagName === 'FORM' && el.classList && el.classList.contains( 'elementor-form' );
	}

	// Fase di cattura: parte prima del gestore di Elementor, che legge il form subito dopo.
	document.addEventListener( 'submit', function ( e ) {
		if ( isElementorForm( e.target ) ) {
			capture( e.target );
		}
	}, true );

	function success( form ) {
		var s = form.__lu3gFm;
		if ( s === null ) {
			return; // form escluso dal tracciamento
		}
		if ( ! s ) {
			s = capture( form ); // invio partito senza l'evento submit (raro): si legge quello che c'è
			if ( ! s ) {
				return;
			}
		}
		if ( s.sent ) {
			return; // stesso invio segnalato due volte
		}
		s.sent = true;

		var payload = {
			event: s.c.e || C.event || 'lead_submit',
			event_id: s.id,
			form_name: s.c.n || form.getAttribute( 'name' ) || '',
			form_id: s.key,
			page_url: location.href,
			page_title: document.title,
			site_domain: location.hostname
		};
		if ( C.pii ) {
			var ud = {};
			if ( s.email ) {
				payload.email = s.email;
				ud.email = s.email;
			}
			if ( s.phone ) {
				payload.phone = s.phone;
				ud.phone_number = s.phone;
			}
			if ( ud.email || ud.phone_number ) {
				payload.user_data = ud;
			}
		}
		push( payload );
		// Il prossimo invio dallo stesso form (es. un popup riaperto) passa di nuovo da capture(): lead nuovo, id nuovo.
	}

	var bound = false;
	function bindJq() {
		var $ = window.jQuery;
		if ( bound || ! $ || ! $.fn || ! $.fn.on ) {
			return bound;
		}
		bound = true;
		// Elementor lancia "submit_success" sul form (o su un suo contenitore): si ascolta su document e si risale al form.
		$( document ).on( 'submit_success', function ( e ) {
			var t = e.target;
			var form = isElementorForm( t ) ? t : ( t && t.querySelector ? t.querySelector( 'form.elementor-form' ) : null );
			if ( form ) {
				success( form );
			}
		} );
		log( 'pronto', C );
		return true;
	}
	if ( ! bindJq() ) {
		document.addEventListener( 'DOMContentLoaded', bindJq );
		window.addEventListener( 'load', bindJq );
		var tries = 0;
		var timer = setInterval( function () {
			tries++;
			if ( bindJq() ) {
				clearInterval( timer );
			} else if ( tries === 120 ) {
				// Dopo un minuto si controlla più di rado (jQuery ritardato fino alla prima interazione).
				clearInterval( timer );
				timer = setInterval( function () {
					if ( bindJq() ) {
						clearInterval( timer );
					}
				}, 2000 );
			}
		}, 500 );
	}

	/* ---------------------------------------------------------------- click sui contatti */

	if ( C.contacts ) {
		document.addEventListener( 'click', function ( e ) {
			var a = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
			if ( ! a ) {
				return;
			}
			var href = a.getAttribute( 'href' ) || '';
			var h = href.toLowerCase();
			var type = '';
			if ( h.indexOf( 'wa.me' ) !== -1 || h.indexOf( 'whatsapp' ) !== -1 ) {
				type = 'whatsapp';
			} else if ( h.indexOf( 'tel:' ) === 0 ) {
				type = 'phone';
			} else if ( h.indexOf( 'mailto:' ) === 0 ) {
				type = 'email';
			}
			if ( ! type ) {
				return;
			}
			push( {
				event: C.contactEvent || 'contact_click',
				event_id: newId(),
				contact_type: type,
				click_url: href,
				click_text: ( a.innerText || a.textContent || '' ).trim().slice( 0, 100 ),
				page_url: location.href,
				site_domain: location.hostname
			} );
		}, true );
	}
}() );
