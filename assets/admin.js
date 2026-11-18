/**
 * LU3G Form Manager — pannello del sito e di rete.
 * Salvataggio delle schede del form e delle impostazioni, anteprima live delle email, placeholder cliccabili, test
 * di email e webhook, dettaglio di richieste e chiamate, reinvio, scansione (anche di rete a blocchi).
 * Tutte le chiamate vanno a admin-ajax con l'azione indicata in LU3G_FM.action (lu3g_fm o lu3g_fm_net).
 */
( function () {
	'use strict';

	var cfg = window.LU3G_FM;
	var page = document.querySelector( '.lu3g-fm' );
	if ( ! cfg || ! page ) {
		return;
	}
	var t = cfg.i18n;
	var toasts = page.querySelector( '[data-toasts]' );

	/* ------------------------------------------------------------ server e avvisi */

	function call( op, data, extra ) {
		var body = new FormData();
		body.append( 'action', cfg.action || 'lu3g_fm' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'op', op );
		body.append( 'data', JSON.stringify( data || {} ) );
		Object.keys( extra || {} ).forEach( function ( k ) {
			body.append( k, extra[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( text ) {
				var json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					throw new Error( t.error );
				}
				if ( ! json.success ) {
					var err = new Error( json.data && json.data.message ? json.data.message : t.error );
					err.data = json.data || {};
					throw err;
				}
				return json.data;
			}, function () {
				throw new Error( t.error );
			} );
	}

	function toast( text, isError ) {
		if ( ! text || ! toasts ) {
			return;
		}
		var el = document.createElement( 'div' );
		el.className = 'fm-toast' + ( isError ? ' is-error' : '' );
		el.setAttribute( 'role', isError ? 'alert' : 'status' );
		el.textContent = text;
		toasts.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-out' );
			setTimeout( function () {
				el.remove();
			}, 350 );
		}, isError ? 7000 : 4000 );
	}

	function busy( btn, on ) {
		if ( btn ) {
			btn.disabled = on;
		}
	}

	/* ------------------------------------------------------------ campi dei form (name="a[b]") */

	function setPath( obj, name, value ) {
		var parts = name.replace( /\]/g, '' ).split( '[' );
		var cur = obj;
		parts.forEach( function ( p, i ) {
			if ( i === parts.length - 1 ) {
				if ( p === '' ) {
					return;
				}
				cur[ p ] = value;
			} else {
				cur[ p ] = cur[ p ] && typeof cur[ p ] === 'object' ? cur[ p ] : {};
				cur = cur[ p ];
			}
		} );
	}

	function collect( form, base ) {
		var out = base ? JSON.parse( JSON.stringify( base ) ) : {};
		var locks = [];
		form.querySelectorAll( 'input[name], select[name], textarea[name]' ).forEach( function ( el ) {
			if ( el.disabled ) {
				return;
			}
			if ( 'locks[]' === el.name ) {
				if ( el.checked ) {
					locks.push( el.value );
				}
				return;
			}
			if ( 'radio' === el.type && ! el.checked ) {
				return;
			}
			setPath( out, el.name, 'checkbox' === el.type ? el.checked : el.value );
		} );
		form.querySelectorAll( '.fm-switch[data-name]' ).forEach( function ( sw ) {
			if ( ! sw.disabled ) {
				setPath( out, sw.getAttribute( 'data-name' ), sw.getAttribute( 'aria-checked' ) === 'true' );
			}
		} );
		if ( form.querySelector( 'input[name="locks[]"]' ) ) {
			out.locks = locks;
		}
		return out;
	}

	function watchDirty( form ) {
		var bar = form.querySelector( '[data-savebar]' );
		var mark = function () {
			if ( bar ) {
				bar.classList.add( 'is-dirty' );
			}
		};
		form.addEventListener( 'input', mark );
		form.addEventListener( 'change', mark );
		form.addEventListener( 'click', function ( e ) {
			var sw = e.target.closest( '.fm-switch' );
			if ( sw && ! sw.disabled ) {
				sw.setAttribute( 'aria-checked', sw.getAttribute( 'aria-checked' ) === 'true' ? 'false' : 'true' );
				mark();
				syncShowIf( form );
			}
		} );
		return function () {
			if ( bar ) {
				bar.classList.remove( 'is-dirty' );
			}
		};
	}

	function syncShowIf( scope ) {
		scope.querySelectorAll( '[data-show-if]' ).forEach( function ( el ) {
			var rule = el.getAttribute( 'data-show-if' ).split( '=' );
			var input = scope.querySelector( '[name="' + rule[ 0 ] + '"]:checked' ) || scope.querySelector( 'select[name="' + rule[ 0 ] + '"]' );
			el.hidden = ! input || input.value !== rule[ 1 ];
		} );
	}

	/* ------------------------------------------------------------ scheda del form */

	var formEl = page.querySelector( '[data-form]' );
	if ( formEl ) {
		var key = formEl.getAttribute( 'data-form-key' );
		var cfgEl = formEl.querySelector( '[data-form-cfg]' );
		var stored = cfgEl ? JSON.parse( cfgEl.textContent ) : {};
		var clean = watchDirty( formEl );
		syncShowIf( formEl );
		formEl.addEventListener( 'change', function () {
			syncShowIf( formEl );
			syncMode();
			syncReply();
		} );

		// "Rispondi a": indirizzo libero solo con l'email LU3G.
		var reply = formEl.querySelector( '[data-reply]' );
		var replyCustom = formEl.querySelector( '[data-reply-custom]' );
		var syncReply = function () {
			if ( reply && replyCustom ) {
				replyCustom.hidden = reply.value !== '__custom';
			}
		};

		// L'editor del corpo vale solo con l'email LU3G.
		var syncMode = function () {
			var mode = formEl.querySelector( 'input[name="admin[mode]"]:checked' );
			var ed = formEl.querySelector( '[data-editor="admin"]' );
			if ( mode && ed ) {
				var lu3g = mode.value === 'lu3g';
				ed.toggleAttribute( 'data-needs-lu3g', ! lu3g );
				ed.querySelector( '[data-lu3g-hint]' ).hidden = lu3g;
				if ( reply ) {
					var opt = reply.querySelector( '[data-lu3g-only]' );
					opt.disabled = ! lu3g;
					if ( ! lu3g && reply.value === '__custom' ) {
						reply.value = '';
						syncReply();
					}
				}
			}
		};
		syncMode();

		formEl.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var btn = formEl.querySelector( '[data-save]' );
			var data = collect( formEl, stored );
			busy( btn, true );
			call( 'save_form', data, { form: key } ).then( function ( d ) {
				stored = data;
				clean();
				toast( d.message );
				// Thank you page: si ricarica per mostrare quella attuale aggiornata.
				if ( formEl.hasAttribute( 'data-reload-on-save' ) ) {
					setTimeout( function () {
						location.reload();
					}, 700 );
				}
			} ).catch( function ( err ) {
				toast( err.message, true );
			} ).then( function () {
				busy( btn, false );
			} );
		} );

		// Thank you page: la pagina scelta riempie l'indirizzo; "torna a Elementor" lo svuota.
		var tyPick = formEl.querySelector( '[data-ty-pick]' );
		var tyInput = formEl.querySelector( 'input[name="redirect"]' );
		if ( tyPick && tyInput ) {
			tyPick.addEventListener( 'change', function () {
				if ( tyPick.value ) {
					tyInput.value = tyPick.value;
					tyInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
			} );
			tyInput.addEventListener( 'input', function () {
				var match = Array.prototype.some.call( tyPick.options, function ( o ) {
					return o.value && o.value === tyInput.value;
				} );
				tyPick.value = match ? tyInput.value : '';
			} );
		}
		var tyReset = formEl.querySelector( '[data-ty-reset]' );
		if ( tyReset && tyInput ) {
			tyReset.addEventListener( 'click', function () {
				tyInput.value = '';
				if ( tyPick ) {
					tyPick.value = '';
				}
				tyInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				toast( 'Premi Salva per confermare.' );
			} );
		}

		// Editor: placeholder, anteprima, test.
		formEl.querySelectorAll( '[data-editor]' ).forEach( function ( ed ) {
			var kind = ed.getAttribute( 'data-editor' );
			var area = ed.querySelector( 'textarea' );
			var frame = ed.querySelector( '[data-preview]' );
			var subjEl = ed.querySelector( '[data-preview-subject]' );
			var subjInput = formEl.querySelector( '[name="' + kind + '[subject]"]' );
			var timer = null;
			var last = null;

			var refresh = function () {
				var payload = { kind: kind, body: area.value, subject: subjInput ? subjInput.value : '' };
				var sig = JSON.stringify( payload );
				if ( sig === last ) {
					return;
				}
				last = sig;
				call( 'preview', payload, { form: key } ).then( function ( d ) {
					frame.srcdoc = d.html;
					subjEl.textContent = d.subject;
				} ).catch( function () {} );
			};
			var later = function () {
				clearTimeout( timer );
				timer = setTimeout( refresh, 350 );
			};
			area.addEventListener( 'input', later );
			if ( subjInput ) {
				subjInput.addEventListener( 'input', later );
			}
			refresh();

			ed.querySelectorAll( '[data-insert]' ).forEach( function ( chip ) {
				chip.addEventListener( 'click', function () {
					var ph = chip.getAttribute( 'data-insert' );
					var s = area.selectionStart || area.value.length;
					var en = area.selectionEnd || s;
					area.value = area.value.slice( 0, s ) + ph + area.value.slice( en );
					area.focus();
					area.selectionStart = area.selectionEnd = s + ph.length;
					area.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					chip.classList.remove( 'is-inserted' );
					void chip.offsetWidth;
					chip.classList.add( 'is-inserted' );
				} );
			} );

			var testBtn = ed.querySelector( '[data-email-test]' );
			testBtn.addEventListener( 'click', function () {
				if ( formEl.querySelector( '[data-savebar].is-dirty' ) ) {
					toast( 'Salva prima le modifiche: il test usa i testi salvati.', true );
					return;
				}
				busy( testBtn, true );
				call( 'email_test', { kind: kind, to: ed.querySelector( '[data-test-to]' ).value }, { form: key } ).then( function ( d ) {
					toast( d.message );
				} ).catch( function ( err ) {
					toast( err.message, true );
				} ).then( function () {
					busy( testBtn, false );
				} );
			} );
		} );

		var hookTest = formEl.querySelector( '[data-webhook-test]' );
		if ( hookTest ) {
			hookTest.addEventListener( 'click', function () {
				if ( formEl.querySelector( '[data-savebar].is-dirty' ) ) {
					toast( 'Salva prima le modifiche: il test usa l\'URL salvato.', true );
					return;
				}
				busy( hookTest, true );
				call( 'webhook_test', {}, { form: key } ).then( function ( d ) {
					toast( d.message );
					setTimeout( function () {
						location.reload();
					}, 1200 );
				} ).catch( function ( err ) {
					toast( err.message, true );
					if ( err.data && err.data.reload ) {
						setTimeout( function () {
							location.reload();
						}, 2000 );
					}
				} ).then( function () {
					busy( hookTest, false );
				} );
			} );
		}
	}

	/* ------------------------------------------------------------ impostazioni (sito e rete) */

	page.querySelectorAll( '[data-settings], [data-net-settings]' ).forEach( function ( form ) {
		var clean = watchDirty( form );
		var reset = [];
		form.addEventListener( 'click', function ( e ) {
			var r = e.target.closest( '[data-reset]' );
			if ( r ) {
				reset.push( r.getAttribute( 'data-reset' ) );
				var box = r.closest( '.fm-setting' );
				box.style.opacity = '.5';
				r.textContent = 'Tornerà al valore della rete quando salvi';
				form.querySelector( '[data-savebar]' ).classList.add( 'is-dirty' );
			}
			var m = e.target.closest( '[data-media]' );
			if ( m && window.wp && wp.media ) {
				var frame = wp.media( { title: t.pick, multiple: false, library: { type: 'image' } } );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					var input = m.parentNode.querySelector( 'input' );
					input.value = att.url;
					input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				} );
				frame.open();
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var data = collect( form );
			data.reset = reset;
			// Un campo da riportare all'eredità non va salvato come personalizzato.
			reset.forEach( function ( k ) {
				if ( data.settings ) {
					delete data.settings[ k ];
				}
			} );
			call( 'save_settings', data ).then( function ( d ) {
				clean();
				toast( d.message );
				if ( d.reload ) {
					setTimeout( function () {
						location.reload();
					}, 700 );
				}
			} ).catch( function ( err ) {
				toast( err.message, true );
			} );
		} );
	} );

	/* ------------------------------------------------------------ scansione */

	var rescan = page.querySelector( '[data-rescan]' );
	if ( rescan ) {
		rescan.addEventListener( 'click', function () {
			busy( rescan, true );
			call( 'rescan' ).then( function ( d ) {
				toast( d.message );
				setTimeout( function () {
					location.reload();
				}, 700 );
			} ).catch( function ( err ) {
				toast( err.message, true );
				busy( rescan, false );
			} );
		} );
	}

	var netScan = page.querySelector( '[data-net-scan]' );
	if ( netScan ) {
		netScan.addEventListener( 'click', function () {
			var box = page.querySelector( '[data-net-progress]' );
			var bar = box.querySelector( 'span' );
			var label = box.querySelector( 'small' );
			var found = 0;
			box.hidden = false;
			busy( netScan, true );
			( function step( offset ) {
				call( 'net_scan', { offset: offset } ).then( function ( d ) {
					found += d.found;
					bar.style.width = Math.min( 100, Math.round( d.next / Math.max( 1, d.total ) * 100 ) ) + '%';
					label.textContent = d.next + ' / ' + d.total + ' siti · ' + found + ' form';
					if ( d.done ) {
						toast( 'Scansione completata: ' + found + ' form in ' + d.total + ' siti.' );
						setTimeout( function () {
							location.reload();
						}, 900 );
					} else {
						step( d.next );
					}
				} ).catch( function ( err ) {
					toast( err.message, true );
					busy( netScan, false );
				} );
			}( 0 ) );
		} );
	}

	/* ------------------------------------------------------------ filtro delle righe */

	page.querySelectorAll( '[data-filter-rows]' ).forEach( function ( input ) {
		var table = page.querySelector( '[data-rows]' );
		input.addEventListener( 'input', function () {
			var q = input.value.trim().toLowerCase();
			table.querySelectorAll( 'tbody tr' ).forEach( function ( tr ) {
				tr.hidden = q !== '' && ( tr.getAttribute( 'data-text' ) || '' ).indexOf( q ) === -1;
			} );
		} );
	} );

	/* ------------------------------------------------------------ dettagli (richieste, chiamate) */

	var modal = page.querySelector( '[data-modal]' );
	var modalBody = modal ? modal.querySelector( '[data-modal-body]' ) : null;
	var lastFocus = null;

	function openModal( node ) {
		lastFocus = document.activeElement;
		modalBody.textContent = '';
		modalBody.appendChild( node );
		modal.hidden = false;
		modal.querySelector( '[data-close]' ).focus();
	}
	function closeModal() {
		modal.hidden = true;
		if ( lastFocus ) {
			lastFocus.focus();
		}
	}
	if ( modal ) {
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal || e.target.closest( '[data-close]' ) ) {
				closeModal();
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! modal.hidden ) {
				closeModal();
			}
		} );
	}

	function detail( title, pairs ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'fm-detail';
		var h = document.createElement( 'h3' );
		h.textContent = title;
		wrap.appendChild( h );
		var dl = document.createElement( 'dl' );
		pairs.forEach( function ( p ) {
			if ( p[ 1 ] === undefined || p[ 1 ] === null || p[ 1 ] === '' ) {
				return;
			}
			var dt = document.createElement( 'dt' );
			var dd = document.createElement( 'dd' );
			dt.textContent = p[ 0 ];
			dd.textContent = Array.isArray( p[ 1 ] ) ? p[ 1 ].join( ', ' ) : String( p[ 1 ] );
			dl.appendChild( dt );
			dl.appendChild( dd );
		} );
		wrap.appendChild( dl );
		return wrap;
	}

	page.addEventListener( 'click', function ( e ) {
		var sub = e.target.closest( '[data-submission]' );
		if ( sub ) {
			call( 'submission_view', { id: sub.getAttribute( 'data-submission' ) } ).then( function ( r ) {
				var pairs = [ [ 'Data', r.when ], [ 'Form', r.form_name ], [ 'Pagina', r.page_url ] ];
				( r.fields || [] ).forEach( function ( f ) {
					pairs.push( [ f.label || f.id, f.value ] );
				} );
				pairs.push( [ 'IP', r.ip ], [ 'Browser', r.user_agent ] );
				var node = detail( 'Richiesta #' + r.id, pairs );
				var actions = document.createElement( 'div' );
				actions.className = 'fm-detail__actions';
				var del = document.createElement( 'button' );
				del.type = 'button';
				del.className = 'fm-btn';
				del.textContent = 'Elimina';
				del.addEventListener( 'click', function () {
					if ( ! window.confirm( t.confirm ) ) {
						return;
					}
					call( 'submission_delete', { id: r.id } ).then( function ( d ) {
						toast( d.message );
						closeModal();
						setTimeout( function () {
							location.reload();
						}, 600 );
					} ).catch( function ( err ) {
						toast( err.message, true );
					} );
				} );
				actions.appendChild( del );
				node.appendChild( actions );
				openModal( node );
			} ).catch( function ( err ) {
				toast( err.message, true );
			} );
			return;
		}

		var log = e.target.closest( '[data-log]' );
		if ( log ) {
			call( 'webhook_view', { id: log.getAttribute( 'data-log' ) } ).then( function ( r ) {
				var node = detail( 'Chiamata #' + r.id, [
					[ 'Data', r.when ],
					[ 'URL', r.method + ' ' + r.url ],
					[ 'Stato HTTP', r.status_code || '—' ],
					[ 'Errore', r.error ],
					[ 'Durata', r.duration_ms + ' ms' ],
				] );
				[ [ 'Payload inviato', r.payload ], [ 'Risposta', r.response ] ].forEach( function ( p ) {
					if ( p[ 1 ] ) {
						var h = document.createElement( 'strong' );
						h.textContent = p[ 0 ];
						var pre = document.createElement( 'pre' );
						pre.textContent = p[ 1 ];
						node.appendChild( h );
						node.appendChild( pre );
					}
				} );
				openModal( node );
			} ).catch( function ( err ) {
				toast( err.message, true );
			} );
			return;
		}

		var again = e.target.closest( '[data-resend]' );
		if ( again ) {
			busy( again, true );
			call( 'webhook_resend', { id: again.getAttribute( 'data-resend' ) } ).then( function ( d ) {
				toast( d.message );
				setTimeout( function () {
					location.reload();
				}, 1200 );
			} ).catch( function ( err ) {
				toast( err.message, true );
				busy( again, false );
			} );
		}
	} );
}() );
