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
		$counts = LU3G_FM_DB::counts_any();
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
			$badge = self::webhook_cell( $f, $hook, $st );
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
		$key = $form['key'];
		$cfg = LU3G_FM_Settings::form( $key );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = in_array( $tab, array( 'admin', 'user' ), true ) ? 'emails' : $tab; // indirizzi della 1.0/1.1
		$cnt = LU3G_FM_DB::counts_any()[ $key ]['n'] ?? 0;

		self::hero(
			$form['name'],
			sprintf(
				/* translators: 1: titolo della pagina, 2: link */
				__( 'In %1$s · %2$s', 'lu3g-form-manager' ),
				esc_html( $form['post_title'] ),
				'<a href="' . esc_url( LU3G_FM_Scanner::edit_url( $form['post_id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'apri in Elementor', 'lu3g-form-manager' ) . ' ↗</a>'
			),
			array(
				__( 'Invii', 'lu3g-form-manager' )       => $cnt,
				__( 'ID elemento', 'lu3g-form-manager' ) => $form['element_id'],
				__( 'Campi', 'lu3g-form-manager' )       => count( $form['fields'] ),
			),
			'<a class="fm-btn fm-btn--light" href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Tutti i form', 'lu3g-form-manager' ) . '</a>'
		);

		self::tabs(
			array(
				'settings'    => __( 'Impostazioni Elementor', 'lu3g-form-manager' ),
				'redirect'    => __( 'Thank you page', 'lu3g-form-manager' ),
				'emails'      => __( 'Email personalizzate', 'lu3g-form-manager' ),
				'webhook'     => __( 'Log webhook', 'lu3g-form-manager' ),
				'submissions' => __( 'Richieste', 'lu3g-form-manager' ),
			),
			$tab,
			array( 'form' => $key )
		);

		if ( 'submissions' === $tab ) {
			self::section_submissions( $key );
			return;
		}
		if ( 'settings' === $tab ) {
			self::section_elementor( $form );
			return;
		}
		if ( 'redirect' === $tab ) {
			self::section_thank_you( $form, $cfg );
			return;
		}
		if ( 'webhook' === $tab ) {
			// Solo il log: le chiamate del webhook di Elementor (URL e Dati avanzati si cambiano in Impostazioni Elementor).
			self::section_webhooks( $key );
			return;
		}

		$emails  = self::email_fields( $form );
		$replace = LU3G_FM_Settings::can_replace_emails();

		echo '<form class="fm-form" data-form-key="' . esc_attr( $key ) . '" data-form autocomplete="off">';

		if ( 'emails' === $tab ) {
			$a  = $cfg['admin'];
			$el = LU3G_FM_Elementor_Editor::get( $form['post_id'], $form['element_id'] );
			$v  = $el ? $el['values'] : array();
			echo '<div class="fm-card fm-card--switch"><div><h3>' . esc_html__( 'Email all\'amministratore col template LU3G', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Al posto dell\'azione "Email" di Elementor parte un\'email con la grafica LU3G e il testo scritto qui. Destinatari e mittente restano quelli dell\'Email di Elementor (scheda Impostazioni Elementor).', 'lu3g-form-manager' ) . '</p>';
			if ( $v ) {
				/* translators: 1: destinatari, 2: mittente */
				echo '<p class="fm-note">' . esc_html( sprintf( __( 'A: %1$s · Da: %2$s', 'lu3g-form-manager' ), $v['email_to'], $v['email_from_name'] . ' <' . $v['email_from'] . '>' ) ) . ( $v['email_to_cc'] ? esc_html( ' · Cc: ' . $v['email_to_cc'] ) : '' ) . ( $v['email_to_bcc'] ? esc_html( ' · Ccn: ' . $v['email_to_bcc'] ) : '' ) . '</p>';
			}
			echo '</div>';
			echo '<input type="hidden" name="admin[mode]" value="' . esc_attr( $a['mode'] ) . '" data-mode-input>';
			self::switch_html( 'admin_mode_switch', __( 'Usa l\'email LU3G al posto dell\'Email di Elementor', 'lu3g-form-manager' ), 'lu3g' === $a['mode'], ! $replace );
			echo '</div>';
			if ( ! $replace ) {
				echo '<p class="fm-note">' . esc_html__( 'Serve Elementor Pro 4.0.3 o successivo.', 'lu3g-form-manager' ) . '</p>';
			}
			echo '<div class="fm-card"><div class="fm-grid">';
			self::input( 'admin[subject]', __( 'Oggetto (vuoto: quello dell\'Email di Elementor)', 'lu3g-form-manager' ), $a['subject'], $v['email_subject'] ?? '', true );
			echo '</div></div>';
			self::editor( 'admin', $a, $form );

			$u = $cfg['user'];
			echo '<div class="fm-card fm-card--switch"><div><h3>' . esc_html__( 'Email di conferma a chi compila, col template LU3G', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Parte dopo ogni invio verso l\'indirizzo scritto nel form, al posto dell\'azione "Email 2" di Elementor.', 'lu3g-form-manager' ) . '</p></div>';
			self::switch_html( 'user[enabled]', __( 'Attiva', 'lu3g-form-manager' ), $u['enabled'], ! $replace );
			echo '</div><div class="fm-card"><div class="fm-grid">';
			echo '<label class="fm-field"><span>' . esc_html__( 'Invia all\'indirizzo del campo', 'lu3g-form-manager' ) . '</span><select name="user[to_field]">';
			echo '<option value="">' . esc_html__( 'Il primo campo email del form', 'lu3g-form-manager' ) . '</option>';
			foreach ( $emails as $f ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $f['id'] ), selected( $u['to_field'], $f['id'], false ), esc_html( $f['label'] ) );
			}
			echo '</select>' . ( $emails ? '' : '<small class="fm-warn">' . esc_html__( 'Il form non ha campi di tipo email: l\'email di conferma non può partire.', 'lu3g-form-manager' ) . '</small>' ) . '</label>';
			self::input( 'user[subject]', __( 'Oggetto', 'lu3g-form-manager' ), $u['subject'], $v['email_subject_2'] ?? 'Abbiamo ricevuto la tua richiesta', true );
			self::input( 'user[from_email]', __( 'Da (email)', 'lu3g-form-manager' ), $u['from_email'], $v['email_from_2'] ?? get_option( 'admin_email' ) );
			self::input( 'user[from_name]', __( 'Da (nome)', 'lu3g-form-manager' ), $u['from_name'], $v['email_from_name_2'] ?? get_bloginfo( 'name' ) );
			self::input( 'user[reply_to]', __( 'Rispondi a', 'lu3g-form-manager' ), $u['reply_to'], $v['email_reply_to_2'] ?? '' );
			echo '</div></div>';
			self::editor( 'user', $u, $form );
		}

		echo '<div class="fm-savebar" data-savebar><span>' . esc_html__( 'Modifiche non salvate', 'lu3g-form-manager' ) . '</span><button type="submit" class="fm-btn fm-btn--primary" data-save>' . esc_html__( 'Salva', 'lu3g-form-manager' ) . '</button></div>';
		// Gli altri campi del form (delle altre schede) viaggiano nascosti: si salva sempre tutto insieme.
		echo '<script type="application/json" data-form-cfg>' . wp_json_encode( $cfg ) . '</script>';
		echo '</form>';

		if ( 'webhook' === $tab ) {
			self::section_webhooks( $key );
		}
	}

	private static function email_fields( array $form ) {
		return array_values(
			array_filter(
				$form['fields'],
				function ( $f ) {
					return 'email' === $f['type'];
				}
			)
		);
	}

	/* ------------------------------------------------------------------ impostazioni di Elementor */

	/** Scheda "Impostazioni Elementor": tutte le impostazioni del form, lette da Elementor e salvate in Elementor. */
	private static function section_elementor( array $form ) {
		$data = LU3G_FM_Elementor_Editor::get( $form['post_id'], $form['element_id'] );
		if ( ! $data ) {
			echo '<div class="fm-alert"><strong>' . esc_html__( 'Impostazioni non leggibili', 'lu3g-form-manager' ) . '</strong><span>' . esc_html__( 'Il form non si trova più nei dati di Elementor di questa pagina: premi "Riscansiona".', 'lu3g-form-manager' ) . '</span></div>';
			return;
		}
		$v      = $data['values'];
		$emails = self::email_fields( $form );
		$backup = LU3G_FM_Elementor_Editor::backup_info( $form['post_id'], $form['element_id'] );
		$acts   = (array) $v['submit_actions'];

		echo '<form class="fm-form" data-el-form data-form-key="' . esc_attr( $form['key'] ) . '" autocomplete="off">';
		echo '<p class="fm-note fm-note--info">' . esc_html__( 'Queste sono le impostazioni vere del form in Elementor: salvando, cambiano anche nell\'editor di Elementor. Un campo vuoto usa il valore predefinito di Elementor (in grigio).', 'lu3g-form-manager' );
		if ( $backup ) {
			/* translators: %s: tempo trascorso */
			echo ' <button type="button" class="fm-linkbtn" data-el-undo>' . esc_html( sprintf( __( 'Annulla l\'ultima modifica (%s fa)', 'lu3g-form-manager' ), human_time_diff( (int) $backup['time'] ) ) ) . '</button>';
		}
		echo '</p>';

		// Generale.
		echo '<div class="fm-card"><h3>' . esc_html__( 'Generale', 'lu3g-form-manager' ) . '</h3><div class="fm-grid">';
		self::el_input( 'form_name', __( 'Nome del modulo', 'lu3g-form-manager' ), $data, true );
		echo '</div><div class="fm-field" style="margin-top:14px"><span>' . esc_html__( 'Azioni dopo l\'invio', 'lu3g-form-manager' ) . '</span><div class="fm-checks">';
		foreach ( LU3G_FM_Elementor_Editor::actions() as $slug => $label ) {
			printf( '<label class="fm-check"><input type="checkbox" data-el-list="submit_actions" value="%1$s" %2$s> %3$s</label>', esc_attr( $slug ), checked( in_array( $slug, $acts, true ), true, false ), esc_html( $label ) );
		}
		echo '</div>';
		if ( $data['other_actions'] ) {
			/* translators: %s: elenco di azioni */
			echo '<small class="fm-help">' . esc_html( sprintf( __( 'Altre azioni attive, lasciate come sono: %s', 'lu3g-form-manager' ), implode( ', ', $data['other_actions'] ) ) ) . '</small>';
		}
		echo '<input type="hidden" data-el-other value="' . esc_attr( wp_json_encode( $data['other_actions'] ) ) . '"></div></div>';

		// Email ed Email 2.
		foreach ( array( '' => __( 'Email', 'lu3g-form-manager' ), '_2' => __( 'Email 2 (di solito la conferma a chi compila)', 'lu3g-form-manager' ) ) as $sfx => $title ) {
			$action = '' === $sfx ? 'email' : 'email2';
			echo '<div class="fm-card" data-el-section="' . esc_attr( $action ) . '"><div class="fm-card__head"><h3>' . esc_html( $title ) . '</h3>' . ( in_array( $action, $acts, true ) ? '<span class="fm-pill fm-pill--ok">' . esc_html__( 'Attiva', 'lu3g-form-manager' ) . '</span>' : '<span class="fm-pill">' . esc_html__( 'Non attiva', 'lu3g-form-manager' ) . '</span>' ) . '</div><div class="fm-grid">';
			self::el_input( 'email_to' . $sfx, __( 'A', 'lu3g-form-manager' ), $data );
			self::el_input( 'email_subject' . $sfx, __( 'Oggetto', 'lu3g-form-manager' ), $data );
			self::el_input( 'email_content' . $sfx, __( 'Messaggio', 'lu3g-form-manager' ), $data, true, 'textarea' );
			self::el_input( 'email_from' . $sfx, __( 'Da Email', 'lu3g-form-manager' ), $data );
			self::el_input( 'email_from_name' . $sfx, __( 'Da Nome', 'lu3g-form-manager' ), $data );
			if ( '' === $sfx ) {
				echo '<label class="fm-field"><span>' . esc_html__( 'Rispondi a', 'lu3g-form-manager' ) . '</span><select data-el="email_reply_to"><option value="">' . esc_html__( '— Nessuno —', 'lu3g-form-manager' ) . '</option>';
				foreach ( $emails as $f ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $f['id'] ), selected( $v['email_reply_to'], $f['id'], false ), esc_html( sprintf( /* translators: %s: etichetta del campo */ __( 'Campo «%s»', 'lu3g-form-manager' ), $f['label'] ) ) );
				}
				echo '</select><small>' . esc_html__( 'In Elementor è uno dei campi email del form.', 'lu3g-form-manager' ) . '</small></label>';
			} else {
				self::el_input( 'email_reply_to_2', __( 'Rispondi a', 'lu3g-form-manager' ), $data );
			}
			self::el_input( 'email_to_cc' . $sfx, __( 'Cc', 'lu3g-form-manager' ), $data );
			self::el_input( 'email_to_bcc' . $sfx, __( 'Ccn', 'lu3g-form-manager' ), $data );
			echo '<div class="fm-field"><span>' . esc_html__( 'Invia come', 'lu3g-form-manager' ) . '</span><select data-el="email_content_type' . esc_attr( $sfx ) . '"><option value="html" ' . selected( $v[ 'email_content_type' . $sfx ], 'html', false ) . '>HTML</option><option value="plain" ' . selected( $v[ 'email_content_type' . $sfx ], 'plain', false ) . '>' . esc_html__( 'Testo semplice', 'lu3g-form-manager' ) . '</option></select></div>';
			echo '</div><div class="fm-field" style="margin-top:14px"><span>' . esc_html__( 'Metadati', 'lu3g-form-manager' ) . '</span><div class="fm-checks">';
			$labels = array( 'date' => __( 'Data', 'lu3g-form-manager' ), 'time' => __( 'Orario', 'lu3g-form-manager' ), 'page_url' => __( 'URL della pagina', 'lu3g-form-manager' ), 'user_agent' => __( 'User agent', 'lu3g-form-manager' ), 'remote_ip' => __( 'IP remoto', 'lu3g-form-manager' ), 'credit' => __( 'Credito', 'lu3g-form-manager' ) );
			foreach ( $labels as $m => $lab ) {
				printf( '<label class="fm-check"><input type="checkbox" data-el-list="form_metadata%1$s" value="%2$s" %3$s> %4$s</label>', esc_attr( $sfx ), esc_attr( $m ), checked( in_array( $m, (array) $v[ 'form_metadata' . $sfx ], true ), true, false ), esc_html( $lab ) );
			}
			echo '</div></div><p class="fm-help" style="margin-top:12px">' . esc_html__( 'Nei testi puoi usare gli shortcode di Elementor, es. [field id="email"] e [all-fields].', 'lu3g-form-manager' ) . '</p></div>';
		}

		// Webhook di Elementor.
		echo '<div class="fm-card" data-el-section="webhook"><div class="fm-card__head"><h3>' . esc_html__( 'Webhook (di Elementor)', 'lu3g-form-manager' ) . '</h3>' . ( in_array( 'webhook', $acts, true ) ? '<span class="fm-pill fm-pill--ok">' . esc_html__( 'Attivo', 'lu3g-form-manager' ) . '</span>' : '<span class="fm-pill">' . esc_html__( 'Non attivo', 'lu3g-form-manager' ) . '</span>' ) . '</div><div class="fm-grid">';
		self::el_input( 'webhooks', __( 'URL del webhook', 'lu3g-form-manager' ), $data, true );
		echo '</div>';
		echo '<button type="button" class="fm-switch" role="switch" aria-checked="' . ( 'yes' === $v['webhooks_advanced_data'] ? 'true' : 'false' ) . '" data-el-bool="webhooks_advanced_data"><span class="fm-switch__track" aria-hidden="true"><span class="fm-switch__thumb"></span></span><span>' . esc_html__( 'Dati avanzati', 'lu3g-form-manager' ) . '</span></button>';
		echo '<p class="fm-help" style="margin-top:10px">' . esc_html__( 'Questo è il webhook di Elementor (senza log). Per il log delle chiamate e il reinvio usa la scheda Webhook LU3G.', 'lu3g-form-manager' ) . '</p></div>';

		echo '<div class="fm-savebar" data-savebar><span>' . esc_html__( 'Modifiche non salvate', 'lu3g-form-manager' ) . '</span><button type="submit" class="fm-btn fm-btn--primary">' . esc_html__( 'Salva in Elementor', 'lu3g-form-manager' ) . '</button></div>';
		echo '</form>';
	}

	/** Campo di testo legato a un'impostazione di Elementor: valore salvato, oppure vuoto col predefinito in grigio. */
	private static function el_input( $key, $label, array $data, $wide = false, $type = 'text' ) {
		$def   = LU3G_FM_Elementor_Editor::fields()[ $key ][1];
		$saved = $data['saved'][ $key ];
		$value = $saved ? (string) $data['values'][ $key ] : '';
		$dyn   = $data['dynamic'][ $key ] ? ' <span class="fm-pill fm-pill--info" title="' . esc_attr__( 'In Elementor questo campo usa un tag dinamico: scrivendo qui lo sostituisci con un valore fisso.', 'lu3g-form-manager' ) . '">' . esc_html__( 'Dinamico', 'lu3g-form-manager' ) . '</span>' : '';
		if ( 'textarea' === $type ) {
			printf( '<label class="fm-field%1$s"><span>%2$s%3$s</span><textarea data-el="%4$s" rows="6" placeholder="%5$s">%6$s</textarea></label>', $wide ? ' fm-field--wide' : '', esc_html( $label ), $dyn, esc_attr( $key ), esc_attr( (string) $def ), esc_textarea( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $dyn costruito escapato
			return;
		}
		printf( '<label class="fm-field%1$s"><span>%2$s%3$s</span><input type="text" data-el="%4$s" value="%5$s" placeholder="%6$s" spellcheck="false"></label>', $wide ? ' fm-field--wide' : '', esc_html( $label ), $dyn, esc_attr( $key ), esc_attr( $value ), esc_attr( (string) $def ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

	/**
	 * Colonna Webhook dell'elenco: il webhook di Elementor (servizio, attivo o spento) e l'esito dell'ultima chiamata
	 * registrata nel log.
	 */
	public static function webhook_cell( array $form, $hook, $st ) {
		$el   = $form['elementor'] ?? array();
		$url  = (string) ( $el['webhook'] ?? '' );
		$on   = in_array( 'webhook', (array) ( $form['actions'] ?? array() ), true );
		$host = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
		$out  = '<div class="fm-hooks">';
		if ( '' !== $url || ! empty( $el['webhook_dynamic'] ) ) {
			$last  = ! $st ? '' : ( $st['ok'] ? ' <span class="fm-pill fm-pill--ok" title="' . esc_attr__( 'Ultima chiamata', 'lu3g-form-manager' ) . '">OK</span>' : ' <span class="fm-pill fm-pill--err" title="' . esc_attr__( 'Ultima chiamata', 'lu3g-form-manager' ) . '">' . esc_html( $st['code'] ? 'HTTP ' . $st['code'] : __( 'Errore', 'lu3g-form-manager' ) ) . '</span>' );
			$out  .= '<span class="fm-hook" title="' . esc_attr( $url ) . '">' . esc_html( '' !== $host ? preg_replace( '/^www\./', '', $host ) : __( 'dinamico', 'lu3g-form-manager' ) ) . ' '
				. ( $on ? '<span class="fm-pill fm-pill--info">' . esc_html__( 'Attivo', 'lu3g-form-manager' ) . '</span>' : '<span class="fm-pill">' . esc_html__( 'Spento', 'lu3g-form-manager' ) . '</span>' ) . $last . '</span>';
		} else {
			$out .= '<span class="fm-hook fm-muted">' . esc_html__( 'Nessuno', 'lu3g-form-manager' ) . '</span>';
		}
		return $out . '</div>';
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
			'lu3g'      => array( 'fm-pill--brand', __( 'Impostata qui (versione precedente)', 'lu3g-form-manager' ) ),
			'elementor' => array( 'fm-pill--ok', __( 'Attiva in Elementor', 'lu3g-form-manager' ) ),
			'dynamic'   => array( 'fm-pill--info', __( 'Dinamica in Elementor', 'lu3g-form-manager' ) ),
			'inactive'  => array( '', __( 'Nessuna attiva', 'lu3g-form-manager' ) ),
			'none'      => array( '', __( 'Nessuna', 'lu3g-form-manager' ) ),
		);

		echo '<div class="fm-card fm-ty"><div class="fm-card__head"><h3>' . esc_html__( 'Thank you page attuale', 'lu3g-form-manager' ) . '</h3><span class="fm-pill ' . esc_attr( $pills[ $ty['source'] ][0] ) . '">' . esc_html( $pills[ $ty['source'] ][1] ) . '</span></div>';
		if ( in_array( $ty['source'], array( 'lu3g', 'elementor' ), true ) ) {
			echo '<div class="fm-ty__current"><div class="fm-ty__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></div><div class="fm-ty__text">';
			echo '<strong>' . esc_html( '' !== $title ? $title : __( 'Indirizzo personalizzato', 'lu3g-form-manager' ) ) . '</strong>';
			echo '<a href="' . esc_url( preg_replace( '/\{\{[a-z0-9_\-]+\}\}/i', '', $ty['url'] ) ) . '" target="_blank" rel="noopener" class="fm-mono">' . esc_html( preg_replace( '/\[field id="?([a-z0-9_\-]+)"?\]/i', '{{$1}}', $ty['url'] ) ) . ' ↗</a></div></div>';
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

		echo '<form class="fm-card" data-ty-form data-form-key="' . esc_attr( $form['key'] ) . '" autocomplete="off"><h3>' . esc_html__( 'Cambia thank you page', 'lu3g-form-manager' ) . '</h3><p class="fm-help">' . esc_html__( 'Scegli una pagina del sito o scrivi un indirizzo, poi "Salva in Elementor": il Redirect del form in Elementor cambia e si attiva da solo. Puoi aggiungere i valori del form, es. ?nome={{nome}}.', 'lu3g-form-manager' ) . '</p>';
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
		$current = in_array( $ty['source'], array( 'elementor', 'lu3g', 'inactive' ), true ) ? preg_replace( '/\[field id="?([a-z0-9_\-]+)"?\]/i', '{{$1}}', $ty['url'] ) : '';
		printf( '<label class="fm-field fm-field--wide"><span>%1$s</span><input type="text" name="redirect" value="%2$s" placeholder="%3$s" spellcheck="false"></label>', esc_html__( 'Indirizzo della thank you page', 'lu3g-form-manager' ), esc_attr( $current ), esc_attr( home_url( '/grazie/' ) ) );
		echo '</div><div class="fm-inline"><button type="submit" class="fm-btn fm-btn--primary">' . esc_html__( 'Salva in Elementor', 'lu3g-form-manager' ) . '</button>';
		if ( in_array( $ty['source'], array( 'elementor', 'lu3g', 'dynamic' ), true ) ) {
			echo '<button type="button" class="fm-btn" data-ty-remove>' . esc_html__( 'Togli la thank you page', 'lu3g-form-manager' ) . '</button>';
		}
		echo '</div></form>';
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
		$src   = LU3G_FM_DB::source( isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = LU3G_FM_DB::query_any( $q, $src );
		$forms = LU3G_FM_Scanner::forms();
		$base  = '' !== $key ? array( 'form' => $key, 'tab' => 'submissions' ) : array( 'tab' => 'submissions' );

		// Fonte: archivio di Elementor (tutti gli invii, anche quelli di prima del plugin) o richieste raccolte dal plugin.
		if ( LU3G_FM_DB::has_elementor_archive() ) {
			echo '<nav class="fm-subtabs">';
			foreach ( array( 'elementor' => __( 'Archivio di Elementor (tutti gli invii)', 'lu3g-form-manager' ), 'lu3g' => __( 'Raccolte da LU3G Form Manager', 'lu3g-form-manager' ) ) as $sk => $sl ) {
				printf( '<a href="%1$s" class="%2$s">%3$s</a>', esc_url( self::url( array_merge( $base, array( 'src' => $sk ) ) ) ), $sk === $src ? 'is-current' : '', esc_html( $sl ) );
			}
			echo '</nav>';
		} else {
			echo '<p class="fm-note">' . esc_html__( 'In questo sito l\'archivio degli invii di Elementor non c\'è (o è disattivato): qui vedi le richieste raccolte da LU3G Form Manager dalla sua installazione.', 'lu3g-form-manager' ) . '</p>';
		}
		$base['src'] = $src;

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
		echo '<input type="hidden" name="src" value="' . esc_attr( $src ) . '">';
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'lu3g_fm_export', 'fk' => $q['form_key'], 's' => $q['search'], 'from' => $q['from'], 'to' => $q['to'], 'src' => $src ), admin_url( 'admin-post.php' ) ), 'lu3g_fm_export' );
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
				'<tr style="--i:%1$d"><td class="fm-nowrap">%2$s</td>%3$s<td>%4$s</td><td class="fm-actions"><button type="button" class="fm-btn fm-btn--small" data-submission="%5$s">%6$s</button></td></tr>',
				(int) $i,
				esc_html( wp_date( 'd/m/Y H:i', strtotime( $r['created'] . ' UTC' ) ) ),
				'' === $key ? '<td>' . esc_html( $r['form_name'] ) . '</td>' : '',
				esc_html( implode( ' · ', array_filter( $preview ) ) ),
				esc_attr( (string) $r['id'] ),
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
			case 'text':
				echo '<input type="text" class="fm-input" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $val ) . '" spellcheck="false"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

			case 'webhook_resend':
				$log = LU3G_FM_DB::get_webhook_log( absint( $data['id'] ?? 0 ) );
				if ( ! $log ) {
					wp_send_json_error( array( 'message' => __( 'Chiamata non trovata.', 'lu3g-form-manager' ) ) );
				}
				// Chiamate del webhook di Elementor: stessi dati allo stesso URL; quelle del vecchio webhook LU3G col suo invio.
				$row = 'elementor' === ( $log['source'] ?? '' ) ? LU3G_FM_Webhook_Log::resend( $log ) : LU3G_FM_Webhook::resend( (int) $log['id'] );
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
				$sid = (string) ( $data['id'] ?? '' );
				$row = 0 === strpos( $sid, 'e' ) ? LU3G_FM_DB::get_elementor_submission( absint( substr( $sid, 1 ) ) ) : LU3G_FM_DB::get_submission( absint( $sid ) );
				if ( ! $row ) {
					wp_send_json_error( array( 'message' => __( 'Richiesta non trovata.', 'lu3g-form-manager' ) ) );
				}
				$row['fields'] = json_decode( (string) $row['fields'], true );
				$row['when']   = wp_date( 'd/m/Y H:i:s', strtotime( $row['created'] . ' UTC' ) );
				wp_send_json_success( $row );
				break;

			case 'submission_delete':
				$sid = (string) ( $data['id'] ?? '' );
				if ( 0 === strpos( $sid, 'e' ) ) {
					LU3G_FM_DB::trash_elementor_submission( absint( substr( $sid, 1 ) ) );
					wp_send_json_success( array( 'message' => __( 'Spostata nel cestino degli Invii di Elementor (da lì si recupera).', 'lu3g-form-manager' ) ) );
				}
				LU3G_FM_DB::delete_submission( absint( $sid ) );
				wp_send_json_success( array( 'message' => __( 'Richiesta eliminata.', 'lu3g-form-manager' ) ) );
				break;

			case 'elementor_save':
				self::need( $form );
				$changes = isset( $data['changes'] ) && is_array( $data['changes'] ) ? $data['changes'] : array();
				if ( isset( $changes['submit_actions'] ) ) {
					// Le azioni non gestite qui (es. Mailchimp) restano come sono.
					$cur                       = LU3G_FM_Elementor_Editor::get( $form['post_id'], $form['element_id'] );
					$keep                      = $cur ? $cur['other_actions'] : array();
					$changes['submit_actions'] = array_values( array_unique( array_merge( array_intersect( (array) $changes['submit_actions'], array_keys( LU3G_FM_Elementor_Editor::actions() ) ), $keep ) ) );
				}
				$r = LU3G_FM_Elementor_Editor::save( $form['post_id'], $form['element_id'], $changes );
				if ( is_wp_error( $r ) ) {
					wp_send_json_error( array( 'message' => $r->get_error_message() ) );
				}
				self::after_elementor_change( $key );
				wp_send_json_success( array( 'message' => __( 'Salvato in Elementor.', 'lu3g-form-manager' ), 'reload' => true ) );
				break;

			case 'elementor_undo':
				self::need( $form );
				$r = LU3G_FM_Elementor_Editor::undo( $form['post_id'], $form['element_id'] );
				if ( is_wp_error( $r ) ) {
					wp_send_json_error( array( 'message' => $r->get_error_message() ) );
				}
				LU3G_FM_Scanner::scan();
				wp_send_json_success( array( 'message' => __( 'Ultima modifica annullata.', 'lu3g-form-manager' ), 'reload' => true ) );
				break;

			case 'ty_save':
			case 'ty_remove':
				self::need( $form );
				$cur  = LU3G_FM_Elementor_Editor::get( $form['post_id'], $form['element_id'] );
				$acts = $cur ? (array) $cur['values']['submit_actions'] : array( 'email' );
				if ( 'ty_save' === $op ) {
					$url = preg_replace( '/\{\{([a-z0-9_\-]+)\}\}/i', '[field id="$1"]', trim( (string) ( $data['url'] ?? '' ) ) );
					if ( ! LU3G_FM_Settings::valid_url( preg_replace( '/\[field id="?[a-z0-9_\-]+"?\]/i', 'x', $url ) ) ) {
						wp_send_json_error( array( 'message' => __( 'Scrivi un indirizzo valido (http o https).', 'lu3g-form-manager' ) ) );
					}
					$changes = array( 'redirect_to' => $url, 'submit_actions' => array_values( array_unique( array_merge( $acts, array( 'redirect' ) ) ) ) );
					$msg     = __( 'Thank you page salvata in Elementor.', 'lu3g-form-manager' );
				} else {
					$changes = array( 'submit_actions' => array_values( array_diff( $acts, array( 'redirect' ) ) ) );
					$msg     = __( 'Thank you page tolta: dopo l\'invio il visitatore resta sulla pagina.', 'lu3g-form-manager' );
				}
				$r = LU3G_FM_Elementor_Editor::save( $form['post_id'], $form['element_id'], $changes );
				if ( is_wp_error( $r ) ) {
					wp_send_json_error( array( 'message' => $r->get_error_message() ) );
				}
				self::after_elementor_change( $key );
				wp_send_json_success( array( 'message' => $msg, 'reload' => true ) );
				break;

			case 'save_settings':
				LU3G_FM_Settings::save_site( isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(), isset( $data['reset'] ) ? array_map( 'sanitize_key', (array) $data['reset'] ) : array() );
				wp_send_json_success( array( 'message' => __( 'Impostazioni salvate.', 'lu3g-form-manager' ), 'reload' => true ) );
				break;
		}
		wp_send_json_error( array( 'message' => __( 'Azione sconosciuta.', 'lu3g-form-manager' ) ) );
	}

	/**
	 * Dopo un salvataggio in Elementor: le vecchie modifiche "a runtime" della 1.0/1.1 su email e thank you page
	 * vengono tolte (ora vale quello che c'è in Elementor) e la scansione si aggiorna (es. nome del form).
	 */
	private static function after_elementor_change( $key ) {
		$cfg = LU3G_FM_Settings::form( $key );
		foreach ( array( 'to', 'from_email', 'from_name', 'reply_to', 'cc', 'bcc' ) as $k ) {
			$cfg['admin'][ $k ] = '';
		}
		$cfg['redirect'] = '';
		LU3G_FM_Settings::save_form( $key, $cfg );
		LU3G_FM_Scanner::scan();
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
		$res = LU3G_FM_DB::query_any(
			array(
				'form_key' => isset( $_GET['fk'] ) ? sanitize_text_field( wp_unslash( $_GET['fk'] ) ) : '',
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'from'     => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
				'to'       => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
				'per_page' => 0,
			),
			isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : ''
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
