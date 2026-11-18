<?php
/**
 * Pannello del singolo sito (permesso manage_options).
 *
 * - Voce "Form" nel menu LU3G se c'è LU3G Hub, altrimenti voce propria "LU3G Form" col logo.
 * - Schede: Form (elenco + "Riscansiona"), Richieste (tutte, con ricerca, date, dettaglio, export CSV),
 *   Webhook (log con "Reinvia"), Impostazioni (template email, webhook di default, retention; su Multisite ogni campo
 *   dice se è ereditato dalla rete, personalizzato o bloccato).
 * - Scheda del form (?form=chiave): Email Admin, Email Utente, Thank you page, Webhook, Richieste.
 * - Azioni via admin-ajax "lu3g_fm" (nonce + manage_options); export CSV via admin-post "lu3g_fm_export".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Admin {

	const PAGE = 'lu3g-form-manager';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'wp_ajax_lu3g_fm', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_lu3g_fm_export', array( __CLASS__, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LU3G_FM_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		global $admin_page_hooks;
		if ( isset( $admin_page_hooks['lu3g-hub'] ) ) {
			$hook = add_submenu_page( 'lu3g-hub', 'LU3G Form Manager', __( 'Form', 'lu3g-form-manager' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
		} else {
			$svg  = (string) file_get_contents( LU3G_FM_PATH . 'assets/lu3g-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$hook = add_menu_page( 'LU3G Form Manager', 'LU3G Form', self::CAP, self::PAGE, array( __CLASS__, 'render' ), 'data:image/svg+xml;base64,' . base64_encode( $svg ), 58 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Apri', 'lu3g-form-manager' ) . '</a>' );
		return $links;
	}

	public static function load() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter(
			'admin_body_class',
			function ( $c ) {
				return $c . ' lu3g-fm-page';
			}
		);
	}

	/** CSS/JS condivisi col pannello di rete. */
	public static function assets( $extra = array() ) {
		wp_enqueue_media();
		wp_enqueue_style( 'lu3g-fm', LU3G_FM_URL . 'assets/admin.css', array(), LU3G_FM_VERSION );
		wp_add_inline_style( 'lu3g-fm', '@font-face{font-family:"LU3G Manrope";src:url(' . esc_url( LU3G_FM_URL . 'assets/fonts/manrope-latin-wght.woff2' ) . ') format("woff2");font-weight:200 800;font-display:swap}' );
		wp_enqueue_script( 'lu3g-fm', LU3G_FM_URL . 'assets/admin.js', array(), LU3G_FM_VERSION, true );
		wp_localize_script(
			'lu3g-fm',
			'LU3G_FM',
			array_merge(
				array(
					'ajax'   => admin_url( 'admin-ajax.php' ),
					'action' => 'lu3g_fm',
					'nonce'  => wp_create_nonce( 'lu3g_fm' ),
					'i18n'  => array(
						'error'   => __( 'Qualcosa è andato storto. Ricarica la pagina e riprova.', 'lu3g-form-manager' ),
						'saved'   => __( 'Salvato.', 'lu3g-form-manager' ),
						'confirm' => __( 'Eliminare questa richiesta? Non si recupera.', 'lu3g-form-manager' ),
						'pick'    => __( 'Scegli il logo', 'lu3g-form-manager' ),
					),
				),
				is_array( $extra ) ? $extra : array()
			)
		);
	}

	/* ------------------------------------------------------------------ pagine */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$key = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'forms'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap lu3g-fm"><h1 class="screen-reader-text">LU3G Form Manager</h1><hr class="wp-header-end">';
		if ( '' !== $key ) {
			$form = LU3G_FM_Scanner::form( $key );
			if ( $form ) {
				self::page_form( $form );
			} else {
				self::hero( __( 'Form non trovato', 'lu3g-form-manager' ), __( 'Forse è stato eliminato: premi "Riscansiona" nell\'elenco dei form.', 'lu3g-form-manager' ) );
			}
		} else {
			self::page_main( $tab );
		}
		echo '<div class="fm-modal" data-modal hidden><div class="fm-modal__box" role="dialog" aria-modal="true"><button type="button" class="fm-modal__close" data-close aria-label="' . esc_attr__( 'Chiudi', 'lu3g-form-manager' ) . '">×</button><div data-modal-body></div></div></div>';
		echo '<div class="fm-toasts" aria-live="polite" data-toasts></div>';
		echo '<footer class="fm-foot">LU3G Form Manager ' . esc_html( LU3G_FM_VERSION ) . '</footer></div>';
	}

	public static function hero( $title, $lead = '', $stats = array(), $actions = '' ) {
		$logo = (string) file_get_contents( LU3G_FM_PATH . 'assets/lu3g-logo.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		echo '<header class="fm-hero"><div class="fm-hero__glow" aria-hidden="true"></div><div class="fm-hero__brand"><span class="fm-hero__logo" aria-hidden="true">' . $logo . '</span><div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG del plugin
		echo '<p class="fm-hero__eyebrow">LU3G Form Manager</p><h2 class="fm-hero__title">' . esc_html( $title ) . '</h2>';
		if ( '' !== $lead ) {
			echo '<p class="fm-hero__lead">' . wp_kses_post( $lead ) . '</p>';
		}
		echo '</div></div>';
		if ( '' !== $actions ) {
			echo '<div class="fm-hero__actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- costruito escapato dal chiamante
		}
		if ( $stats ) {
			echo '<dl class="fm-stats">';
			foreach ( $stats as $label => $value ) {
				echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
			}
			echo '</dl>';
		}
		echo '</header>';
	}

	private static function tabs( array $tabs, $current, $base_args = array() ) {
		echo '<nav class="fm-tabs" aria-label="' . esc_attr__( 'Sezioni', 'lu3g-form-manager' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( self::url( array_merge( $base_args, array( 'tab' => $slug ) ) ) ),
				$slug === $current ? 'is-current' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	private static function page_main( $tab ) {
		$forms  = LU3G_FM_Scanner::forms();
		$counts = LU3G_FM_DB::counts();
		$total  = array_sum( wp_list_pluck( $counts, 'n' ) );
		$status = LU3G_FM_DB::webhook_status();
		$errors = count(
			array_filter(
				$status,
				function ( $s ) {
					return ! $s['ok'];
				}
			)
		);

		self::hero(
			__( 'I form del sito', 'lu3g-form-manager' ),
			__( 'Destinatari, thank you page, webhook ed email di tutti i form di Elementor, senza aprire Elementor.', 'lu3g-form-manager' ),
			array(
				__( 'Form trovati', 'lu3g-form-manager' )        => count( $forms ),
				__( 'Richieste salvate', 'lu3g-form-manager' )   => $total,
				__( 'Webhook in errore', 'lu3g-form-manager' )   => $errors,
			),
			'<button type="button" class="fm-btn fm-btn--light" data-rescan><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.9-3.9M4 4v4h4M4 13a8 8 0 0 0 14.9 3.9M20 20v-4h-4"/></svg>' . esc_html__( 'Riscansiona', 'lu3g-form-manager' ) . '</button>'
		);

		if ( ! LU3G_FM_Settings::can_replace_emails() ) {
			/* translators: %s: versione di Elementor Pro */
			echo '<div class="fm-alert"><strong>' . esc_html__( 'Elementor Pro va aggiornato per le email LU3G', 'lu3g-form-manager' ) . '</strong><span>' . esc_html( sprintf( __( 'Con Elementor Pro %s le email personalizzate col template LU3G non sono disponibili (serve la 4.0.3 o successiva). Override dei destinatari, thank you page, webhook e richieste funzionano.', 'lu3g-form-manager' ), ELEMENTOR_PRO_VERSION ) ) . '</span></div>';
		}

		self::tabs(
			array(
				'forms'       => __( 'Form', 'lu3g-form-manager' ),
				'submissions' => __( 'Richieste', 'lu3g-form-manager' ),
				'webhooks'    => __( 'Webhook', 'lu3g-form-manager' ),
				'settings'    => __( 'Impostazioni', 'lu3g-form-manager' ),
			),
			$tab
		);

		switch ( $tab ) {
			case 'submissions':
				self::section_submissions( '' );
				break;
			case 'webhooks':
				self::section_webhooks( '' );
				break;
			case 'settings':
				self::section_settings();
				break;
			default:
				self::section_forms( $forms, $counts, $status );
		}
	}

	private static function section_forms( $forms, $counts, $status ) {
		echo '<div class="fm-card">';
		if ( ! $forms ) {
			echo '<p class="fm-empty">' . esc_html__( 'Nessun form di Elementor trovato. Se ne hai appena creato uno, premi "Riscansiona".', 'lu3g-form-manager' ) . '</p></div>';
			return;
		}
		$scan = LU3G_FM_Scanner::last_scan();
		echo '<div class="fm-card__head"><h3>' . esc_html__( 'Form', 'lu3g-form-manager' ) . '</h3><input type="search" class="fm-search" placeholder="' . esc_attr__( 'Cerca per nome o pagina…', 'lu3g-form-manager' ) . '" data-filter-rows></div>';
		echo '<div class="fm-table-wrap"><table class="fm-table" data-rows><thead><tr><th>' . esc_html__( 'Form', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Si trova in', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Thank you page', 'lu3g-form-manager' ) . '</th><th class="num">' . esc_html__( 'Invii', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Webhook', 'lu3g-form-manager' ) . '</th><th></th></tr></thead><tbody>';
		$i = 0;
		foreach ( $forms as $key => $f ) {
			$n     = $counts[ $key ]['n'] ?? 0;
			$hook  = LU3G_FM_Settings::webhook_for( $key );
			$st    = $status[ $key ] ?? null;
			$badge = ! $hook ? '<span class="fm-pill">' . esc_html__( 'Nessuno', 'lu3g-form-manager' ) . '</span>'
				: ( ! $st ? '<span class="fm-pill fm-pill--info">' . esc_html__( 'Mai chiamato', 'lu3g-form-manager' ) . '</span>'
				: ( $st['ok'] ? '<span class="fm-pill fm-pill--ok">OK</span>' : '<span class="fm-pill fm-pill--err">' . esc_html( $st['code'] ? 'HTTP ' . $st['code'] : __( 'Errore', 'lu3g-form-manager' ) ) . '</span>' ) );
			printf(
				'<tr style="--i:%1$d" data-text="%2$s"><td><a class="fm-strong" href="%3$s">%4$s</a>%5$s<small class="fm-mono">%6$s</small></td><td>%7$s <a href="%8$s" target="_blank" rel="noopener" class="fm-link">%9$s ↗</a><small>%10$s</small></td><td>%14$s</td><td class="num">%11$d</td><td>%12$s</td><td class="fm-actions"><a class="fm-btn fm-btn--small" href="%3$s">%13$s</a></td></tr>',
				(int) $i++,
				esc_attr( strtolower( $f['name'] . ' ' . $f['post_title'] . ' ' . $f['form_id'] ) ),
				esc_url( self::url( array( 'form' => $key ) ) ),
				esc_html( $f['name'] ),
				LU3G_FM_Settings::has_overrides( $key ) ? ' <span class="fm-pill fm-pill--brand">' . esc_html__( 'Personalizzato', 'lu3g-form-manager' ) . '</span>' : '',
				esc_html( 'ID ' . $f['element_id'] . ( '' !== $f['form_id'] ? ' · ' . $f['form_id'] : '' ) ),
				esc_html( $f['post_title'] ),
				esc_url( LU3G_FM_Scanner::edit_url( $f['post_id'] ) ),
				esc_html__( 'Elementor', 'lu3g-form-manager' ),
				esc_html( LU3G_FM_Scanner::type_label( $f['post_type'] ) . ( 'publish' !== $f['status'] ? ' · ' . $f['status'] : '' ) ),
				(int) $n,
				$badge, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- costruito escapato
				esc_html__( 'Gestisci', 'lu3g-form-manager' ),
				self::thank_you_cell( self::thank_you( $f, LU3G_FM_Settings::form( $key ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- costruito escapato
			);
		}
		echo '</tbody></table></div>';
		/* translators: %s: tempo trascorso */
		echo '<p class="fm-note">' . esc_html( sprintf( __( 'Ultima scansione: %s fa.', 'lu3g-form-manager' ), human_time_diff( $scan ) ) ) . '</p></div>';
	}

	/* ------------------------------------------------------------------ scheda del form */

	private static function page_form( array $form ) {
		$key  = $form['key'];
		$cfg  = LU3G_FM_Settings::form( $key );
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'admin'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cnt  = LU3G_FM_DB::counts()[ $key ]['n'] ?? 0;

		self::hero(
			$form['name'],
			sprintf(
				/* translators: 1: titolo della pagina, 2: link */
				__( 'In %1$s · %2$s', 'lu3g-form-manager' ),
				esc_html( $form['post_title'] ),
				'<a href="' . esc_url( LU3G_FM_Scanner::edit_url( $form['post_id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'apri in Elementor', 'lu3g-form-manager' ) . ' ↗</a>'
			),
			array(
				__( 'Invii', 'lu3g-form-manager' )        => $cnt,
				__( 'ID elemento', 'lu3g-form-manager' )  => $form['element_id'],
				__( 'Campi', 'lu3g-form-manager' )        => count( $form['fields'] ),
			),
			'<a class="fm-btn fm-btn--light" href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Tutti i form', 'lu3g-form-manager' ) . '</a>'
		);

		self::tabs(
			array(
				'admin'       => __( 'Email Admin', 'lu3g-form-manager' ),
				'user'        => __( 'Email Utente', 'lu3g-form-manager' ),
				'redirect'    => __( 'Thank you page', 'lu3g-form-manager' ),
				'webhook'     => __( 'Webhook', 'lu3g-form-manager' ),
				'submissions' => __( 'Richieste', 'lu3g-form-manager' ),
			),
			$tab,
			array( 'form' => $key )
		);

		if ( 'submissions' === $tab ) {
			self::section_submissions( $key );
			return;
		}

		$emails  = array_values(
			array_filter(
				$form['fields'],
				function ( $f ) {
					return 'email' === $f['type'];
				}
			)
		);
		$el      = $form['elementor'];
		$replace = LU3G_FM_Settings::can_replace_emails();

		echo '<form class="fm-form" data-form-key="' . esc_attr( $key ) . '" data-form autocomplete="off"' . ( 'redirect' === $tab ? ' data-reload-on-save' : '' ) . '>';

		if ( 'admin' === $tab ) {
			$a = $cfg['admin'];
			echo '<div class="fm-card"><h3>' . esc_html__( 'Quale email riceve l\'amministratore', 'lu3g-form-manager' ) . '</h3>';
			echo '<div class="fm-seg" role="radiogroup">';
			self::radio( 'admin[mode]', 'elementor', $a['mode'], __( 'Email di Elementor', 'lu3g-form-manager' ), __( 'Cambio solo destinatari, oggetto e mittente; il testo resta quello di Elementor.', 'lu3g-form-manager' ) );
			self::radio( 'admin[mode]', 'lu3g', $a['mode'], __( 'Email LU3G personalizzata', 'lu3g-form-manager' ), $replace ? __( 'Testo e grafica decisi qui, col template LU3G e l\'anteprima.', 'lu3g-form-manager' ) : __( 'Serve Elementor Pro 4.0.3 o successivo.', 'lu3g-form-manager' ), ! $replace );
			echo '</div>';
			if ( ! in_array( 'email', $form['actions'], true ) ) {
				echo '<p class="fm-note">' . esc_html__( 'In Elementor questo form non ha l\'azione "Email": gli override dell\'email di Elementor non hanno effetto finché non la aggiungi (o usi l\'email LU3G).', 'lu3g-form-manager' ) . '</p>';
			}
			echo '</div>';

			echo '<div class="fm-card"><h3>' . esc_html__( 'Destinatari e mittente', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Lascia vuoto per usare il valore impostato in Elementor (lo vedi in grigio). Più indirizzi separati da virgola; puoi usare i placeholder, es. {{email}}.', 'lu3g-form-manager' ) . '</p><div class="fm-grid">';
			self::input( 'admin[to]', __( 'A', 'lu3g-form-manager' ), $a['to'], $el['to'] ? $el['to'] : get_option( 'admin_email' ) );
			self::input( 'admin[subject]', __( 'Oggetto', 'lu3g-form-manager' ), $a['subject'], $el['subject'] ? $el['subject'] : 'New message from "' . get_bloginfo( 'name' ) . '"', true );
			self::input( 'admin[from_email]', __( 'Da (email)', 'lu3g-form-manager' ), $a['from_email'], $el['from_email'] );
			self::input( 'admin[from_name]', __( 'Da (nome)', 'lu3g-form-manager' ), $a['from_name'], $el['from_name'] ? $el['from_name'] : get_bloginfo( 'name' ) );
			echo '<label class="fm-field"><span>' . esc_html__( 'Rispondi a', 'lu3g-form-manager' ) . '</span><select name="admin[reply_to]" data-reply>';
			echo '<option value="">' . esc_html__( 'Come in Elementor', 'lu3g-form-manager' ) . ( $el['reply_to'] ? ' (' . esc_html( $el['reply_to'] ) . ')' : '' ) . '</option>';
			foreach ( $emails as $f ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $f['id'] ), selected( $a['reply_to'], $f['id'], false ), esc_html( sprintf( /* translators: %s: etichetta del campo */ __( 'Campo «%s»', 'lu3g-form-manager' ), $f['label'] ) ) );
			}
			$custom = '' !== $a['reply_to'] && ! in_array( $a['reply_to'], wp_list_pluck( $emails, 'id' ), true );
			echo '<option value="__custom" ' . selected( $custom, true, false ) . ' data-lu3g-only>' . esc_html__( 'Un indirizzo (solo email LU3G)…', 'lu3g-form-manager' ) . '</option></select>';
			echo '<input type="text" name="admin[reply_to_custom]" value="' . esc_attr( $custom ? $a['reply_to'] : '' ) . '" placeholder="info@cliente.it" data-reply-custom' . ( $custom ? '' : ' hidden' ) . '></label>';
			self::input( 'admin[cc]', __( 'Cc', 'lu3g-form-manager' ), $a['cc'], $el['cc'] );
			self::input( 'admin[bcc]', __( 'Ccn', 'lu3g-form-manager' ), $a['bcc'], $el['bcc'] );
			echo '</div></div>';

			self::editor( 'admin', $a, $form );
		} elseif ( 'user' === $tab ) {
			$u = $cfg['user'];
			echo '<div class="fm-card fm-card--switch"><div><h3>' . esc_html__( 'Email di conferma a chi compila', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Parte dopo ogni invio verso l\'indirizzo scritto nel form. Se il form ha l\'azione "Email 2" di Elementor, questa la sostituisce.', 'lu3g-form-manager' ) . '</p></div>';
			self::switch_html( 'user[enabled]', __( 'Attiva', 'lu3g-form-manager' ), $u['enabled'], ! $replace );
			echo '</div>';
			if ( ! $replace ) {
				echo '<p class="fm-note">' . esc_html__( 'Serve Elementor Pro 4.0.3 o successivo.', 'lu3g-form-manager' ) . '</p>';
			}
			echo '<div class="fm-card"><h3>' . esc_html__( 'Destinatario e mittente', 'lu3g-form-manager' ) . '</h3><div class="fm-grid">';
			echo '<label class="fm-field"><span>' . esc_html__( 'Invia all\'indirizzo del campo', 'lu3g-form-manager' ) . '</span><select name="user[to_field]">';
			echo '<option value="">' . esc_html__( 'Il primo campo email del form', 'lu3g-form-manager' ) . '</option>';
			foreach ( $emails as $f ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $f['id'] ), selected( $u['to_field'], $f['id'], false ), esc_html( $f['label'] ) );
			}
			echo '</select>' . ( $emails ? '' : '<small class="fm-warn">' . esc_html__( 'Il form non ha campi di tipo email: l\'email di conferma non può partire.', 'lu3g-form-manager' ) . '</small>' ) . '</label>';
			self::input( 'user[subject]', __( 'Oggetto', 'lu3g-form-manager' ), $u['subject'], 'Abbiamo ricevuto la tua richiesta', true );
			self::input( 'user[from_email]', __( 'Da (email)', 'lu3g-form-manager' ), $u['from_email'], get_option( 'admin_email' ) );
			self::input( 'user[from_name]', __( 'Da (nome)', 'lu3g-form-manager' ), $u['from_name'], get_bloginfo( 'name' ) );
			self::input( 'user[reply_to]', __( 'Rispondi a', 'lu3g-form-manager' ), $u['reply_to'], get_option( 'admin_email' ) );
			echo '</div></div>';
			self::editor( 'user', $u, $form );
		} elseif ( 'redirect' === $tab ) {
			self::section_thank_you( $form, $cfg );
		} elseif ( 'webhook' === $tab ) {
			$w = $cfg['webhook'];
			$g = LU3G_FM_Settings::general();
			echo '<div class="fm-card"><h3>' . esc_html__( 'Webhook', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'A ogni invio i dati partono in JSON verso questo URL (Make, Zapier, n8n, un CRM...). Il webhook di Elementor, se c\'è, resta com\'è.', 'lu3g-form-manager' ) . '</p>';
			echo '<div class="fm-seg" role="radiogroup">';
			self::radio( 'webhook[mode]', 'default', $w['mode'], __( 'Webhook di default', 'lu3g-form-manager' ), $g['webhook_enabled'] && $g['webhook_url'] ? $g['webhook_url'] : __( 'Nessuno impostato (scheda Impostazioni).', 'lu3g-form-manager' ) );
			self::radio( 'webhook[mode]', 'custom', $w['mode'], __( 'Webhook di questo form', 'lu3g-form-manager' ), __( 'URL e metodo qui sotto.', 'lu3g-form-manager' ) );
			self::radio( 'webhook[mode]', 'off', $w['mode'], __( 'Disattivato', 'lu3g-form-manager' ), __( 'Nessuna chiamata per questo form.', 'lu3g-form-manager' ) );
			echo '</div><div class="fm-grid" data-show-if="webhook[mode]=custom">';
			self::input( 'webhook[url]', __( 'URL', 'lu3g-form-manager' ), $w['url'], 'https://hook.eu1.make.com/…', true );
			echo '<label class="fm-field"><span>' . esc_html__( 'Metodo', 'lu3g-form-manager' ) . '</span><select name="webhook[method]">';
			foreach ( array( 'POST', 'PUT', 'PATCH', 'GET' ) as $m ) {
				printf( '<option %1$s>%2$s</option>', selected( $w['method'], $m, false ), esc_html( $m ) );
			}
			echo '</select></label></div>';
			echo '<div class="fm-inline"><button type="button" class="fm-btn" data-webhook-test>' . esc_html__( 'Invia test', 'lu3g-form-manager' ) . '</button><span class="fm-help">' . esc_html__( 'Manda un invio di esempio all\'URL salvato (salva prima le modifiche).', 'lu3g-form-manager' ) . '</span></div>';
			echo '</div>';
		}

		echo '<div class="fm-savebar" data-savebar><span>' . esc_html__( 'Modifiche non salvate', 'lu3g-form-manager' ) . '</span><button type="submit" class="fm-btn fm-btn--primary" data-save>' . esc_html__( 'Salva', 'lu3g-form-manager' ) . '</button></div>';
		// Gli altri campi del form (delle altre schede) viaggiano nascosti: si salva sempre tutto insieme.
		echo '<script type="application/json" data-form-cfg>' . wp_json_encode( $cfg ) . '</script>';
		echo '</form>';

		if ( 'webhook' === $tab ) {
			self::section_webhooks( $key );
		}
	}

	/**
	 * Thank you page effettiva di un form e da dove viene.
	 *
	 * @return array{url:string,source:string} source: lu3g (impostata qui), elementor, dynamic (tag dinamico di Elementor),
	 *                                          inactive (indirizzo in Elementor ma azione Redirect spenta), none.
	 */
	public static function thank_you( array $form, array $cfg ) {
		$el      = $form['elementor'];
		$has     = in_array( 'redirect', (array) $form['actions'], true );
		$own     = (string) $cfg['redirect'];
		if ( '' !== $own ) {
			return array( 'url' => $own, 'source' => 'lu3g' );
		}
		if ( $has && ! empty( $el['redirect_dynamic'] ) ) {
			return array( 'url' => '', 'source' => 'dynamic' );
		}
		if ( $has && '' !== (string) $el['redirect'] ) {
			return array( 'url' => (string) $el['redirect'], 'source' => 'elementor' );
		}
		if ( ! $has && '' !== (string) $el['redirect'] ) {
			return array( 'url' => (string) $el['redirect'], 'source' => 'inactive' );
		}
		return array( 'url' => '', 'source' => 'none' );
	}

	/** Titolo della pagina del sito a cui punta un URL ('' se l'URL è esterno o non è una pagina). */
	private static function page_title_of( $url ) {
		$id = url_to_postid( preg_replace( '/\{\{[a-z0-9_\-]+\}\}/i', '', (string) $url ) );
		return $id ? get_the_title( $id ) : '';
	}

	/** Etichetta breve della thank you page per l'elenco dei form. */
	private static function thank_you_cell( array $ty ) {
		$labels = array(
			'lu3g'      => __( 'Impostata qui', 'lu3g-form-manager' ),
			'elementor' => __( 'Da Elementor', 'lu3g-form-manager' ),
			'dynamic'   => __( 'Dinamica in Elementor', 'lu3g-form-manager' ),
			'inactive'  => __( 'In Elementor ma spenta', 'lu3g-form-manager' ),
			'none'      => __( 'Nessuna', 'lu3g-form-manager' ),
		);
		if ( 'none' === $ty['source'] || 'dynamic' === $ty['source'] ) {
			return '<span class="fm-muted">' . esc_html( $labels[ $ty['source'] ] ) . '</span>';
		}
		$title = self::page_title_of( $ty['url'] );
		$path  = (string) wp_parse_url( $ty['url'], PHP_URL_PATH );
		$text  = '' !== $title ? $title : ( '' !== $path && '/' !== $path ? $path : $ty['url'] );
		return '<span class="' . ( 'inactive' === $ty['source'] ? 'fm-muted' : '' ) . '">' . esc_html( wp_html_excerpt( $text, 40, '…' ) ) . '</span><small>' . esc_html( $labels[ $ty['source'] ] ) . '</small>';
	}

	/** Scheda Thank you page: quella attuale in evidenza, poi la scelta tra le pagine del sito o un indirizzo. */
	private static function section_thank_you( array $form, array $cfg ) {
		$ty    = self::thank_you( $form, $cfg );
		$el    = $form['elementor'];
		$title = '' !== $ty['url'] ? self::page_title_of( $ty['url'] ) : '';
		$pills = array(
			'lu3g'      => array( 'fm-pill--brand', __( 'Impostata qui', 'lu3g-form-manager' ) ),
			'elementor' => array( 'fm-pill--info', __( 'Impostata in Elementor', 'lu3g-form-manager' ) ),
			'dynamic'   => array( 'fm-pill--info', __( 'Dinamica in Elementor', 'lu3g-form-manager' ) ),
			'inactive'  => array( '', __( 'Nessuna attiva', 'lu3g-form-manager' ) ),
			'none'      => array( '', __( 'Nessuna', 'lu3g-form-manager' ) ),
		);

		echo '<div class="fm-card fm-ty"><div class="fm-card__head"><h3>' . esc_html__( 'Thank you page attuale', 'lu3g-form-manager' ) . '</h3><span class="fm-pill ' . esc_attr( $pills[ $ty['source'] ][0] ) . '">' . esc_html( $pills[ $ty['source'] ][1] ) . '</span></div>';
		if ( in_array( $ty['source'], array( 'lu3g', 'elementor' ), true ) ) {
			echo '<div class="fm-ty__current"><div class="fm-ty__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></div><div class="fm-ty__text">';
			echo '<strong>' . esc_html( '' !== $title ? $title : __( 'Indirizzo personalizzato', 'lu3g-form-manager' ) ) . '</strong>';
			echo '<a href="' . esc_url( preg_replace( '/\{\{[a-z0-9_\-]+\}\}/i', '', $ty['url'] ) ) . '" target="_blank" rel="noopener" class="fm-mono">' . esc_html( $ty['url'] ) . ' ↗</a></div></div>';
			if ( 'lu3g' === $ty['source'] && '' !== (string) $el['redirect'] ) {
				/* translators: %s: URL impostato in Elementor */
				echo '<p class="fm-note">' . esc_html( sprintf( __( 'In Elementor c\'è %s, ma vale quella impostata qui.', 'lu3g-form-manager' ), $el['redirect'] ) ) . '</p>';
			}
		} elseif ( 'dynamic' === $ty['source'] ) {
			echo '<p class="fm-help">' . esc_html__( 'In Elementor l\'indirizzo è un tag dinamico (es. "URL del post"): si conosce solo al momento dell\'invio. Puoi sostituirlo qui sotto con un indirizzo fisso.', 'lu3g-form-manager' ) . '</p>';
		} elseif ( 'inactive' === $ty['source'] ) {
			/* translators: %s: URL impostato in Elementor */
			echo '<p class="fm-help">' . esc_html( sprintf( __( 'In Elementor è scritto %s, ma l\'azione "Redirect" non è attiva: dopo l\'invio il visitatore resta sulla pagina e vede il messaggio di conferma. Scegline una qui sotto per attivarla senza aprire Elementor.', 'lu3g-form-manager' ), $el['redirect'] ) ) . '</p>';
		} else {
			echo '<p class="fm-help">' . esc_html__( 'Dopo l\'invio il visitatore resta sulla pagina e vede il messaggio di conferma del form. Scegli una pagina qui sotto per mandarlo a una thank you page.', 'lu3g-form-manager' ) . '</p>';
		}
		echo '</div>';

		// Pagine del sito, con in cima quelle che sembrano di ringraziamento.
		$pages   = get_pages( array( 'post_status' => 'publish', 'number' => 500, 'sort_column' => 'post_title' ) );
		$suggest = array();
		$others  = array();
		foreach ( (array) $pages as $p ) {
			$row = array( 'url' => get_permalink( $p ), 'title' => '' !== $p->post_title ? $p->post_title : '#' . $p->ID );
			if ( preg_match( '/grazi|thank|conferm|ricevut|success|inviat/i', $p->post_title . ' ' . $p->post_name ) ) {
				$suggest[] = $row;
			} else {
				$others[] = $row;
			}
		}

		echo '<div class="fm-card"><h3>' . esc_html__( 'Cambia thank you page', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Scegli una pagina del sito o scrivi un indirizzo. Puoi aggiungere i valori del form, es. ?nome={{nome}}. Vale da subito, senza aprire Elementor.', 'lu3g-form-manager' ) . '</p>';
		echo '<div class="fm-grid"><label class="fm-field fm-field--wide"><span>' . esc_html__( 'Pagina del sito', 'lu3g-form-manager' ) . '</span><select data-ty-pick><option value="">' . esc_html__( '— Scegli una pagina —', 'lu3g-form-manager' ) . '</option>';
		foreach ( array( __( 'Pagine di ringraziamento', 'lu3g-form-manager' ) => $suggest, __( 'Tutte le pagine', 'lu3g-form-manager' ) => $others ) as $group => $list ) {
			if ( ! $list ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $list as $row ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $row['url'] ), selected( $ty['url'], $row['url'], false ), esc_html( $row['title'] . ' — ' . (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ) );
			}
			echo '</optgroup>';
		}
		echo '</select></label>';
		self::input( 'redirect', __( 'Indirizzo della thank you page', 'lu3g-form-manager' ), $cfg['redirect'], '' !== (string) $el['redirect'] ? $el['redirect'] : home_url( '/grazie/' ), true );
		echo '</div>';
		if ( '' !== $cfg['redirect'] ) {
			echo '<div class="fm-inline"><button type="button" class="fm-btn" data-ty-reset>' . esc_html( '' !== (string) $el['redirect'] ? __( 'Torna a quella di Elementor', 'lu3g-form-manager' ) : __( 'Togli la thank you page', 'lu3g-form-manager' ) ) . '</button></div>';
		}
		echo '</div>';
	}

	/** Editor del corpo con placeholder cliccabili, anteprima live e invio di test. */
	private static function editor( $kind, array $cfg, array $form ) {
		$disabled = 'admin' === $kind && 'lu3g' !== $cfg['mode'];
		echo '<div class="fm-card fm-editor" data-editor="' . esc_attr( $kind ) . '"' . ( $disabled ? ' data-needs-lu3g' : '' ) . '>';
		echo '<div class="fm-card__head"><h3>' . esc_html__( 'Testo dell\'email', 'lu3g-form-manager' ) . '</h3>';
		echo '<span class="fm-help" data-lu3g-hint' . ( $disabled ? '' : ' hidden' ) . '>' . esc_html__( 'Si usa solo con "Email LU3G personalizzata".', 'lu3g-form-manager' ) . '</span></div>';
		echo '<div class="fm-chips" aria-label="' . esc_attr__( 'Placeholder: clicca per inserire', 'lu3g-form-manager' ) . '">';
		foreach ( LU3G_FM_Emails::placeholders( $form ) as $ph => $label ) {
			printf( '<button type="button" class="fm-chip" data-insert="%1$s" title="%2$s">%1$s</button>', esc_attr( $ph ), esc_attr( $label ) );
		}
		echo '</div><div class="fm-editor__cols"><label class="fm-field"><span>' . esc_html__( 'Corpo (HTML)', 'lu3g-form-manager' ) . '</span>';
		printf( '<textarea name="%1$s[body]" rows="16" spellcheck="false" placeholder="%2$s">%3$s</textarea>', esc_attr( $kind ), esc_attr( LU3G_FM_Emails::default_body( $kind ) ), esc_textarea( $cfg['body'] ) );
		echo '<small>' . esc_html__( 'Vuoto: si usa il testo predefinito (in grigio).', 'lu3g-form-manager' ) . '</small></label>';
		echo '<div class="fm-preview"><div class="fm-preview__bar"><span>' . esc_html__( 'Anteprima con dati di esempio', 'lu3g-form-manager' ) . '</span><span class="fm-preview__subject" data-preview-subject></span></div><iframe title="' . esc_attr__( 'Anteprima dell\'email', 'lu3g-form-manager' ) . '" sandbox="" data-preview></iframe></div></div>';
		echo '<div class="fm-inline"><input type="email" class="fm-input" placeholder="' . esc_attr( wp_get_current_user()->user_email ) . '" value="' . esc_attr( wp_get_current_user()->user_email ) . '" data-test-to><button type="button" class="fm-btn" data-email-test>' . esc_html__( 'Invia email di test', 'lu3g-form-manager' ) . '</button></div>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ richieste */

	private static function section_submissions( $key ) {
		$q = array(
			'form_key' => $key,
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'page'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		if ( '' === $key && isset( $_GET['fk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$q['form_key'] = sanitize_text_field( wp_unslash( $_GET['fk'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$res   = LU3G_FM_DB::query_submissions( $q );
		$forms = LU3G_FM_Scanner::forms();
		$base  = '' !== $key ? array( 'form' => $key, 'tab' => 'submissions' ) : array( 'tab' => 'submissions' );

		echo '<div class="fm-card"><form class="fm-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		foreach ( array_merge( array( 'page' => self::PAGE ), $base ) as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		if ( '' === $key ) {
			echo '<select name="fk"><option value="">' . esc_html__( 'Tutti i form', 'lu3g-form-manager' ) . '</option>';
			foreach ( $forms as $k => $f ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $k ), selected( $q['form_key'], $k, false ), esc_html( $f['name'] . ' — ' . $f['post_title'] ) );
			}
			echo '</select>';
		}
		echo '<input type="search" name="s" value="' . esc_attr( $q['search'] ) . '" placeholder="' . esc_attr__( 'Cerca nei campi…', 'lu3g-form-manager' ) . '">';
		echo '<label>' . esc_html__( 'Dal', 'lu3g-form-manager' ) . ' <input type="date" name="from" value="' . esc_attr( $q['from'] ) . '"></label>';
		echo '<label>' . esc_html__( 'al', 'lu3g-form-manager' ) . ' <input type="date" name="to" value="' . esc_attr( $q['to'] ) . '"></label>';
		echo '<button class="fm-btn">' . esc_html__( 'Filtra', 'lu3g-form-manager' ) . '</button>';
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'lu3g_fm_export', 'fk' => $q['form_key'], 's' => $q['search'], 'from' => $q['from'], 'to' => $q['to'] ), admin_url( 'admin-post.php' ) ), 'lu3g_fm_export' );
		echo '<a class="fm-btn fm-btn--primary" href="' . esc_url( $export ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M5 20h14"/></svg>' . esc_html__( 'Esporta CSV', 'lu3g-form-manager' ) . '</a>';
		echo '</form>';

		if ( ! $res['rows'] ) {
			echo '<p class="fm-empty">' . esc_html__( 'Nessuna richiesta.', 'lu3g-form-manager' ) . '</p></div>';
			return;
		}
		echo '<div class="fm-table-wrap"><table class="fm-table"><thead><tr><th>' . esc_html__( 'Data', 'lu3g-form-manager' ) . '</th>' . ( '' === $key ? '<th>' . esc_html__( 'Form', 'lu3g-form-manager' ) . '</th>' : '' ) . '<th>' . esc_html__( 'Anteprima', 'lu3g-form-manager' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $res['rows'] as $i => $r ) {
			$fields  = json_decode( (string) $r['fields'], true );
			$preview = array();
			foreach ( array_slice( (array) $fields, 0, 3 ) as $f ) {
				$v         = is_array( $f['value'] ?? '' ) ? implode( ', ', $f['value'] ) : (string) ( $f['value'] ?? '' );
				$preview[] = wp_html_excerpt( $v, 60, '…' );
			}
			printf(
				'<tr style="--i:%1$d"><td class="fm-nowrap">%2$s</td>%3$s<td>%4$s</td><td class="fm-actions"><button type="button" class="fm-btn fm-btn--small" data-submission="%5$d">%6$s</button></td></tr>',
				(int) $i,
				esc_html( wp_date( 'd/m/Y H:i', strtotime( $r['created'] . ' UTC' ) ) ),
				'' === $key ? '<td>' . esc_html( $r['form_name'] ) . '</td>' : '',
				esc_html( implode( ' · ', array_filter( $preview ) ) ),
				(int) $r['id'],
				esc_html__( 'Dettaglio', 'lu3g-form-manager' )
			);
		}
		echo '</tbody></table></div>';
		self::pager( $res['total'], $q['page'], 25, array_merge( $base, array_filter( array( 's' => $q['search'], 'from' => $q['from'], 'to' => $q['to'], 'fk' => '' === $key ? $q['form_key'] : '' ) ) ) );
		echo '</div>';
	}

	private static function pager( $total, $page, $per, $args ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages < 2 ) {
			return;
		}
		echo '<nav class="fm-pager">';
		for ( $p = 1; $p <= $pages; $p++ ) {
			if ( $pages > 12 && $p > 2 && $p < $pages - 1 && abs( $p - $page ) > 2 ) {
				if ( 3 === $p || $pages - 2 === $p ) {
					echo '<span>…</span>';
				}
				continue;
			}
			printf( '<a href="%1$s"%2$s>%3$d</a>', esc_url( self::url( array_merge( $args, array( 'paged' => $p ) ) ) ), $p === $page ? ' aria-current="page"' : '', (int) $p );
		}
		echo '</nav>';
	}

	/* ------------------------------------------------------------------ log webhook */

	private static function section_webhooks( $key ) {
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res  = LU3G_FM_DB::query_webhook_log( $key, $page );
		$forms = LU3G_FM_Scanner::forms();
		echo '<div class="fm-card"><div class="fm-card__head"><h3>' . esc_html__( 'Log delle chiamate', 'lu3g-form-manager' ) . '</h3></div>';
		if ( ! $res['rows'] ) {
			echo '<p class="fm-empty">' . esc_html__( 'Ancora nessuna chiamata.', 'lu3g-form-manager' ) . '</p></div>';
			return;
		}
		echo '<div class="fm-table-wrap"><table class="fm-table"><thead><tr><th>' . esc_html__( 'Data', 'lu3g-form-manager' ) . '</th>' . ( '' === $key ? '<th>' . esc_html__( 'Form', 'lu3g-form-manager' ) . '</th>' : '' ) . '<th>' . esc_html__( 'URL', 'lu3g-form-manager' ) . '</th><th>' . esc_html__( 'Esito', 'lu3g-form-manager' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $res['rows'] as $i => $r ) {
			$ok = $r['status_code'] >= 200 && $r['status_code'] < 300 && empty( $r['error'] );
			printf(
				'<tr style="--i:%1$d"><td class="fm-nowrap">%2$s%3$s</td>%4$s<td class="fm-break"><span class="fm-mono">%5$s</span> %6$s</td><td><span class="fm-pill %7$s">%8$s</span> <small>%9$d ms</small></td><td class="fm-actions"><button type="button" class="fm-btn fm-btn--small" data-log="%10$d">%11$s</button> <button type="button" class="fm-btn fm-btn--small" data-resend="%10$d">%12$s</button></td></tr>',
				(int) $i,
				esc_html( wp_date( 'd/m/Y H:i:s', strtotime( $r['created'] . ' UTC' ) ) ),
				$r['is_test'] ? ' <span class="fm-pill fm-pill--info">test</span>' : '',
				'' === $key ? '<td>' . esc_html( $forms[ $r['form_key'] ]['name'] ?? $r['form_key'] ) . '</td>' : '',
				esc_html( $r['method'] ),
				esc_html( $r['url'] ),
				$ok ? 'fm-pill--ok' : 'fm-pill--err',
				esc_html( $r['status_code'] ? 'HTTP ' . $r['status_code'] : __( 'Errore', 'lu3g-form-manager' ) ),
				(int) $r['duration_ms'],
				(int) $r['id'],
				esc_html__( 'Dettaglio', 'lu3g-form-manager' ),
				esc_html__( 'Reinvia', 'lu3g-form-manager' )
			);
		}
		echo '</tbody></table></div>';
		self::pager( $res['total'], $page, 25, '' !== $key ? array( 'form' => $key, 'tab' => 'webhook' ) : array( 'tab' => 'webhooks' ) );
		echo '</div>';
	}

	/* ------------------------------------------------------------------ impostazioni */

	private static function section_settings() {
		$g     = LU3G_FM_Settings::general();
		$multi = is_multisite();
		$net   = LU3G_FM_Settings::network();
		echo '<form class="fm-form" data-settings autocomplete="off">';
		echo '<div class="fm-card"><h3>' . esc_html__( 'Template delle email', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Cornice delle email LU3G (Admin e Utente) di tutti i form.', 'lu3g-form-manager' ) . '</p>';
		self::setting( 'logo', $g, $net, $multi, 'media' );
		self::setting( 'color', $g, $net, $multi, 'color' );
		self::setting( 'footer', $g, $net, $multi, 'textarea' );
		echo '</div><div class="fm-card"><h3>' . esc_html__( 'Webhook di default', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Vale per i form impostati su "Webhook di default".', 'lu3g-form-manager' ) . '</p>';
		self::setting( 'webhook_enabled', $g, $net, $multi, 'switch' );
		self::setting( 'webhook_url', $g, $net, $multi, 'url' );
		self::setting( 'webhook_method', $g, $net, $multi, 'method' );
		echo '</div><div class="fm-card"><h3>' . esc_html__( 'Conservazione dei dati', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Le richieste più vecchie si cancellano da sole ogni giorno (GDPR). 0 = non cancellare mai.', 'lu3g-form-manager' ) . '</p>';
		self::setting( 'retention_months', $g, $net, $multi, 'number' );
		echo '</div><div class="fm-savebar" data-savebar><span>' . esc_html__( 'Modifiche non salvate', 'lu3g-form-manager' ) . '</span><button type="submit" class="fm-btn fm-btn--primary">' . esc_html__( 'Salva', 'lu3g-form-manager' ) . '</button></div></form>';
	}

	/**
	 * Un campo delle impostazioni generali. Su Multisite mostra "Ereditato dalla rete" / "Personalizzato" / "Bloccato".
	 *
	 * @param string $type media|color|textarea|switch|url|method|number.
	 * @param array  $net  Valori di rete (per il pannello di rete sono anche i valori da mostrare).
	 */
	public static function setting( $key, array $values, array $net, $multi, $type, $network_mode = false ) {
		$label = LU3G_FM_Settings::labels()[ $key ];
		$state = $network_mode ? 'network' : ( $multi ? LU3G_FM_Settings::state( $key ) : 'single' );
		$val   = $values[ $key ];
		$lock  = 'locked' === $state;
		echo '<div class="fm-setting" data-setting="' . esc_attr( $key ) . '" data-state="' . esc_attr( $state ) . '">';
		echo '<div class="fm-setting__head"><span class="fm-setting__label">' . esc_html( $label ) . '</span>';
		if ( 'inherited' === $state ) {
			echo '<span class="fm-pill fm-pill--info">' . esc_html__( 'Ereditato dalla rete', 'lu3g-form-manager' ) . '</span>';
		} elseif ( 'custom' === $state ) {
			echo '<span class="fm-pill fm-pill--brand">' . esc_html__( 'Personalizzato', 'lu3g-form-manager' ) . '</span><button type="button" class="fm-linkbtn" data-reset="' . esc_attr( $key ) . '">' . esc_html__( 'Torna al valore della rete', 'lu3g-form-manager' ) . '</button>';
		} elseif ( 'locked' === $state ) {
			echo '<span class="fm-pill">🔒 ' . esc_html__( 'Bloccato dalla rete', 'lu3g-form-manager' ) . '</span>';
		} elseif ( 'network' === $state ) {
			$locked = in_array( $key, LU3G_FM_Settings::locks(), true );
			echo '<label class="fm-lock"><input type="checkbox" name="locks[]" value="' . esc_attr( $key ) . '" ' . checked( $locked, true, false ) . '> ' . esc_html__( 'Blocca sui siti', 'lu3g-form-manager' ) . '</label>';
		}
		echo '</div>';
		$dis  = $lock ? ' disabled' : '';
		$name = 'settings[' . $key . ']';
		switch ( $type ) {
			case 'media':
				echo '<div class="fm-media"><input type="url" class="fm-input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" placeholder="https://…/logo.png"' . $dis . '><button type="button" class="fm-btn" data-media' . $dis . '>' . esc_html__( 'Scegli', 'lu3g-form-manager' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'color':
				echo '<input type="color" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'textarea':
				echo '<textarea class="fm-input" name="' . esc_attr( $name ) . '" rows="3"' . $dis . '>' . esc_textarea( $val ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'switch':
				self::switch_html( $name, __( 'Attivo', 'lu3g-form-manager' ), $val, $lock );
				break;
			case 'method':
				echo '<select name="' . esc_attr( $name ) . '"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( array( 'POST', 'PUT', 'PATCH', 'GET' ) as $m ) {
					printf( '<option %1$s>%2$s</option>', selected( $val, $m, false ), esc_html( $m ) );
				}
				echo '</select>';
				break;
			case 'number':
				echo '<input type="number" class="fm-input fm-input--short" min="0" max="120" name="' . esc_attr( $name ) . '" value="' . (int) $val . '"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			default:
				echo '<input type="url" class="fm-input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" placeholder="https://…"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( 'inherited' === $state || 'custom' === $state ) {
			$nv = $net[ $key ];
			$nv = is_bool( $nv ) ? ( $nv ? __( 'attivo', 'lu3g-form-manager' ) : __( 'spento', 'lu3g-form-manager' ) ) : wp_strip_all_tags( (string) $nv );
			/* translators: %s: valore di rete */
			echo '<small class="fm-help">' . esc_html( sprintf( __( 'Valore della rete: %s', 'lu3g-form-manager' ), '' !== $nv ? wp_html_excerpt( $nv, 80, '…' ) : '—' ) ) . '</small>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ piccoli componenti */

	private static function input( $name, $label, $value, $placeholder = '', $wide = false ) {
		printf(
			'<label class="fm-field%5$s"><span>%1$s</span><input type="text" name="%2$s" value="%3$s" placeholder="%4$s" spellcheck="false"></label>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			$wide ? ' fm-field--wide' : ''
		);
	}

	private static function radio( $name, $value, $current, $label, $hint = '', $disabled = false ) {
		printf(
			'<label class="fm-option%6$s"><input type="radio" name="%1$s" value="%2$s" %3$s%7$s><span><strong>%4$s</strong><small>%5$s</small></span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $current, $value, false ),
			esc_html( $label ),
			esc_html( $hint ),
			$disabled ? ' is-disabled' : '',
			$disabled ? ' disabled' : ''
		);
	}

	public static function switch_html( $name, $label, $on, $disabled = false ) {
		printf(
			'<button type="button" class="fm-switch" role="switch" aria-checked="%1$s" data-name="%2$s"%3$s><span class="fm-switch__track" aria-hidden="true"><span class="fm-switch__thumb"></span></span><span>%4$s</span></button>',
			$on ? 'true' : 'false',
			esc_attr( $name ),
			$disabled ? ' disabled' : '',
			esc_html( $label )
		);
	}

	/* ------------------------------------------------------------------ azioni */

	public static function ajax() {
		check_ajax_referer( 'lu3g_fm', 'nonce' );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Non hai i permessi per questa operazione.', 'lu3g-form-manager' ) ), 403 );
		}
		$data = isset( $_POST['data'] ) ? json_decode( wp_unslash( $_POST['data'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanificato dove si usa
		$data = is_array( $data ) ? $data : array();
		$key  = isset( $_POST['form'] ) ? sanitize_text_field( wp_unslash( $_POST['form'] ) ) : '';
		$form = '' !== $key ? LU3G_FM_Scanner::form( $key ) : null;

		switch ( $op ) {
			case 'rescan':
				$c = LU3G_FM_Scanner::scan();
				/* translators: %d: numero di form */
				wp_send_json_success( array( 'message' => sprintf( _n( 'Trovato %d form.', 'Trovati %d form.', count( $c['forms'] ), 'lu3g-form-manager' ), count( $c['forms'] ) ) ) );
				break;

			case 'save_form':
				self::need( $form );
				if ( isset( $data['admin']['reply_to'] ) && '__custom' === $data['admin']['reply_to'] ) {
					$data['admin']['reply_to'] = $data['admin']['reply_to_custom'] ?? '';
				}
				LU3G_FM_Settings::save_form( $key, $data );
				wp_send_json_success( array( 'message' => __( 'Impostazioni del form salvate.', 'lu3g-form-manager' ) ) );
				break;

			case 'preview':
				self::need( $form );
				$kind = 'user' === ( $data['kind'] ?? '' ) ? 'user' : 'admin';
				$ctx  = LU3G_FM_Emails::sample_context( $form );
				$body = wp_kses_post( (string) ( $data['body'] ?? '' ) );
				$subj = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );
				wp_send_json_success(
					array(
						'html'    => LU3G_FM_Emails::render( '' !== trim( $body ) ? $body : LU3G_FM_Emails::default_body( $kind ), $ctx ),
						'subject' => LU3G_FM_Emails::replace_text( '' !== $subj ? $subj : ( 'user' === $kind ? 'Abbiamo ricevuto la tua richiesta' : 'Nuova richiesta da {{form_name}}' ), $ctx ),
					)
				);
				break;

			case 'email_test':
				self::need( $form );
				$to = sanitize_email( (string) ( $data['to'] ?? '' ) );
				if ( ! is_email( $to ) ) {
					wp_send_json_error( array( 'message' => __( 'Scrivi un indirizzo email valido.', 'lu3g-form-manager' ) ) );
				}
				$kind  = 'user' === ( $data['kind'] ?? '' ) ? 'user' : 'admin';
				$email = LU3G_FM_Settings::form( $key )[ $kind ];
				$email = array_merge( $email, array( 'to' => $to, 'cc' => '', 'bcc' => '', 'subject' => '[TEST] ' . ( $email['subject'] ? $email['subject'] : ( 'user' === $kind ? 'Abbiamo ricevuto la tua richiesta' : 'Nuova richiesta da {{form_name}}' ) ) ) );
				$sent  = LU3G_FM_Emails::send( $email, LU3G_FM_Emails::sample_context( $form ), $kind );
				if ( is_wp_error( $sent ) ) {
					wp_send_json_error( array( 'message' => $sent->get_error_message() ) );
				}
				/* translators: %s: indirizzo */
				wp_send_json_success( array( 'message' => sprintf( __( 'Email di test inviata a %s (con i testi salvati).', 'lu3g-form-manager' ), $to ) ) );
				break;

			case 'webhook_test':
				self::need( $form );
				$row = LU3G_FM_Webhook::test( $form );
				self::webhook_reply( $row );
				break;

			case 'webhook_resend':
				$row = LU3G_FM_Webhook::resend( absint( $data['id'] ?? 0 ) );
				self::webhook_reply( $row );
				break;

			case 'webhook_view':
				$log = LU3G_FM_DB::get_webhook_log( absint( $data['id'] ?? 0 ) );
				if ( ! $log ) {
					wp_send_json_error( array( 'message' => __( 'Chiamata non trovata.', 'lu3g-form-manager' ) ) );
				}
				$log['when']    = wp_date( 'd/m/Y H:i:s', strtotime( $log['created'] . ' UTC' ) );
				$log['payload'] = wp_json_encode( json_decode( (string) $log['payload'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				wp_send_json_success( $log );
				break;

			case 'submission_view':
				$row = LU3G_FM_DB::get_submission( absint( $data['id'] ?? 0 ) );
				if ( ! $row ) {
					wp_send_json_error( array( 'message' => __( 'Richiesta non trovata.', 'lu3g-form-manager' ) ) );
				}
				$row['fields'] = json_decode( (string) $row['fields'], true );
				$row['when']   = wp_date( 'd/m/Y H:i:s', strtotime( $row['created'] . ' UTC' ) );
				wp_send_json_success( $row );
				break;

			case 'submission_delete':
				LU3G_FM_DB::delete_submission( absint( $data['id'] ?? 0 ) );
				wp_send_json_success( array( 'message' => __( 'Richiesta eliminata.', 'lu3g-form-manager' ) ) );
				break;

			case 'save_settings':
				LU3G_FM_Settings::save_site( isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(), isset( $data['reset'] ) ? array_map( 'sanitize_key', (array) $data['reset'] ) : array() );
				wp_send_json_success( array( 'message' => __( 'Impostazioni salvate.', 'lu3g-form-manager' ), 'reload' => true ) );
				break;
		}
		wp_send_json_error( array( 'message' => __( 'Azione sconosciuta.', 'lu3g-form-manager' ) ) );
	}

	private static function need( $form ) {
		if ( ! $form ) {
			wp_send_json_error( array( 'message' => __( 'Form non trovato: riscansiona.', 'lu3g-form-manager' ) ) );
		}
	}

	private static function webhook_reply( $row ) {
		if ( is_wp_error( $row ) ) {
			wp_send_json_error( array( 'message' => $row->get_error_message() ) );
		}
		$ok = $row['status_code'] >= 200 && $row['status_code'] < 300 && empty( $row['error'] );
		/* translators: 1: stato HTTP, 2: millisecondi */
		$msg = $ok ? sprintf( __( 'Webhook OK: HTTP %1$d in %2$d ms.', 'lu3g-form-manager' ), $row['status_code'], $row['duration_ms'] ) : sprintf( __( 'Webhook non riuscito: %s', 'lu3g-form-manager' ), $row['error'] );
		if ( $ok ) {
			wp_send_json_success( array( 'message' => $msg, 'reload' => true ) );
		}
		wp_send_json_error( array( 'message' => $msg, 'reload' => true ) );
	}

	/* ------------------------------------------------------------------ export CSV */

	/**
	 * Export CSV delle richieste filtrate. Separatore ";" e BOM UTF-8: si apre bene in Excel italiano.
	 * Una colonna per ogni campo (unione dei campi delle righe esportate).
	 */
	public static function export() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'lu3g_fm_export' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per questa operazione.', 'lu3g-form-manager' ) );
		}
		$res = LU3G_FM_DB::query_submissions(
			array(
				'form_key' => isset( $_GET['fk'] ) ? sanitize_text_field( wp_unslash( $_GET['fk'] ) ) : '',
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
				'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
				'per_page' => 0,
			)
		);
		self::csv( $res['rows'], 'richieste-' . gmdate( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Scrive il CSV e termina. $rows: righe della tabella richieste (con eventuale 'site' per la rete).
	 */
	public static function csv( array $rows, $filename ) {
		$cols = array();
		foreach ( $rows as &$r ) {
			$r['_f'] = array();
			foreach ( (array) json_decode( (string) $r['fields'], true ) as $f ) {
				$label            = (string) ( $f['label'] ?? $f['id'] ?? '' );
				$cols[ $label ]   = true;
				$r['_f'][ $label ] = is_array( $f['value'] ?? '' ) ? implode( ', ', $f['value'] ) : (string) ( $f['value'] ?? '' );
			}
		}
		unset( $r );
		$cols = array_keys( $cols );
		$site = isset( $rows[0]['site'] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$head = array_merge( $site ? array( 'Sito' ) : array(), array( 'Data', 'Form', 'Pagina' ), $cols, array( 'IP' ) );
		fputcsv( $out, $head, ';' );
		foreach ( $rows as $r ) {
			$line = $site ? array( $r['site'] ) : array();
			$line = array_merge( $line, array( wp_date( 'd/m/Y H:i', strtotime( $r['created'] . ' UTC' ) ), $r['form_name'], $r['page_url'] ) );
			foreach ( $cols as $c ) {
				$v = $r['_f'][ $c ] ?? '';
				// Niente formule in Excel: un valore che inizia con = + - @ viene preceduto da un apostrofo.
				$line[] = preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
			}
			$line[] = $r['ip'];
			fputcsv( $out, $line, ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
