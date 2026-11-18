<?php
/**
 * Impostazioni.
 *
 * Generali (template email, webhook di default, retention), a tre livelli:
 *   valori di base del plugin → rete (get_site_option, solo Multisite) → sito (get_option).
 * Il sito salva solo i campi che personalizza: un campo non salvato eredita dalla rete. La rete può bloccare dei campi:
 * il sito allora usa il valore di rete e non lo può cambiare. Su un'installazione singola c'è solo il livello sito.
 *
 * Per ogni form (opzione del sito "lu3g_fm_forms_cfg", chiave = "id post:id elemento"): override dell'Email Admin,
 * Email Utente, thank you page e webhook. Un campo vuoto lascia valido il valore impostato in Elementor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Settings {

	const NETWORK = 'lu3g_fm_network';        // site option: valori di rete
	const LOCKS   = 'lu3g_fm_network_locks';  // site option: campi bloccati dalla rete
	const SITE    = 'lu3g_fm_site';           // option: valori personalizzati del sito
	const FORMS   = 'lu3g_fm_forms_cfg';      // option: override per form

	/** Campi generali con il loro valore di base. */
	public static function defaults() {
		return array(
			'logo'             => '',
			'color'            => '#09090b',
			'footer'           => '',
			'retention_months' => 0,          // 0 = non cancellare mai
			'webhook_enabled'  => false,      // webhook di default per i form senza un webhook proprio
			'webhook_url'      => '',
			'webhook_method'   => 'POST',
			// Conversioni (1.3.0): eventi verso gli strumenti già presenti nel sito (GTM, GA4, Google Ads, Meta Pixel).
			'conv_enabled'      => false,
			'conv_gtm'          => true,
			'conv_gtm_event'    => 'lu3g_form_submit',
			'conv_ga4'          => true,
			'conv_ga4_event'    => 'generate_lead',
			'conv_ads'          => false,
			'conv_ads_send_to'  => '',
			'conv_ads_value'    => '',
			'conv_ads_currency' => 'EUR',
			'conv_meta'         => true,
			'conv_meta_event'   => 'Lead',
		);
	}

	/** Chiavi delle conversioni (le stesse per le impostazioni generali e per ogni form). */
	public static function conv_keys() {
		return array( 'conv_gtm', 'conv_gtm_event', 'conv_ga4', 'conv_ga4_event', 'conv_ads', 'conv_ads_send_to', 'conv_ads_value', 'conv_ads_currency', 'conv_meta', 'conv_meta_event' );
	}

	public static function labels() {
		return array(
			'logo'             => __( 'Logo', 'lu3g-form-manager' ),
			'color'            => __( 'Colore principale', 'lu3g-form-manager' ),
			'footer'           => __( 'Footer delle email', 'lu3g-form-manager' ),
			'retention_months' => __( 'Conserva le richieste per (mesi)', 'lu3g-form-manager' ),
			'webhook_enabled'  => __( 'Webhook di default attivo', 'lu3g-form-manager' ),
			'webhook_url'      => __( 'URL del webhook di default', 'lu3g-form-manager' ),
			'webhook_method'   => __( 'Metodo del webhook di default', 'lu3g-form-manager' ),
			'conv_enabled'      => __( 'Conversioni attive su tutti i form', 'lu3g-form-manager' ),
			'conv_gtm'          => __( 'Google Tag Manager (dataLayer)', 'lu3g-form-manager' ),
			'conv_gtm_event'    => __( 'Nome dell\'evento GTM', 'lu3g-form-manager' ),
			'conv_ga4'          => __( 'Google Analytics 4', 'lu3g-form-manager' ),
			'conv_ga4_event'    => __( 'Nome dell\'evento GA4', 'lu3g-form-manager' ),
			'conv_ads'          => __( 'Google Ads', 'lu3g-form-manager' ),
			'conv_ads_send_to'  => __( 'Google Ads: send_to (AW-XXXXXXXXX/etichetta)', 'lu3g-form-manager' ),
			'conv_ads_value'    => __( 'Google Ads: valore della conversione', 'lu3g-form-manager' ),
			'conv_ads_currency' => __( 'Google Ads: valuta', 'lu3g-form-manager' ),
			'conv_meta'         => __( 'Meta Pixel', 'lu3g-form-manager' ),
			'conv_meta_event'   => __( 'Evento Meta (Lead, Contact, CompleteRegistration… o personalizzato)', 'lu3g-form-manager' ),
		);
	}

	/* ------------------------------------------------------------------ livelli */

	public static function network_raw() {
		$v = is_multisite() ? get_site_option( self::NETWORK, array() ) : array();
		return is_array( $v ) ? $v : array();
	}

	public static function locks() {
		$v = is_multisite() ? get_site_option( self::LOCKS, array() ) : array();
		return is_array( $v ) ? array_values( array_intersect( $v, array_keys( self::defaults() ) ) ) : array();
	}

	public static function site_raw() {
		$v = get_option( self::SITE, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Valori di rete (base + rete). */
	public static function network() {
		return array_merge( self::defaults(), array_intersect_key( self::network_raw(), self::defaults() ) );
	}

	/** Valori effettivi del sito corrente: base → rete → sito (tranne i campi bloccati). */
	public static function general() {
		$out = self::network();
		$own = array_diff_key( array_intersect_key( self::site_raw(), self::defaults() ), array_flip( self::locks() ) );
		return self::normalize( array_merge( $out, $own ) );
	}

	/**
	 * Stato di un campo per il sito: "locked" (bloccato dalla rete), "custom" (personalizzato), "inherited".
	 */
	public static function state( $key ) {
		if ( in_array( $key, self::locks(), true ) ) {
			return 'locked';
		}
		return array_key_exists( $key, self::site_raw() ) ? 'custom' : 'inherited';
	}

	/**
	 * Salva i campi generali del sito. Un campo in $reset torna a ereditare; i campi bloccati sono ignorati.
	 */
	public static function save_site( array $input, array $reset = array() ) {
		$raw   = self::site_raw();
		$locks = self::locks();
		foreach ( self::clean( $input ) as $k => $v ) {
			if ( ! in_array( $k, $locks, true ) ) {
				$raw[ $k ] = $v;
			}
		}
		foreach ( $reset as $k ) {
			unset( $raw[ $k ] );
		}
		update_option( self::SITE, $raw, false );
		return self::general();
	}

	public static function save_network( array $input, array $locks ) {
		update_site_option( self::NETWORK, array_merge( self::network_raw(), self::clean( $input ) ) );
		update_site_option( self::LOCKS, array_values( array_intersect( array_map( 'sanitize_key', $locks ), array_keys( self::defaults() ) ) ) );
		return self::network();
	}

	/** Sanifica i campi generali arrivati dal pannello (solo le chiavi presenti). */
	private static function clean( array $in ) {
		$out = array();
		if ( isset( $in['logo'] ) ) {
			$out['logo'] = esc_url_raw( trim( (string) $in['logo'] ) );
		}
		if ( isset( $in['color'] ) ) {
			$c            = sanitize_hex_color( (string) $in['color'] );
			$out['color'] = $c ? $c : '#09090b';
		}
		if ( isset( $in['footer'] ) ) {
			$out['footer'] = wp_kses_post( (string) $in['footer'] );
		}
		if ( isset( $in['retention_months'] ) ) {
			$out['retention_months'] = max( 0, min( 120, absint( $in['retention_months'] ) ) );
		}
		if ( isset( $in['webhook_enabled'] ) ) {
			$out['webhook_enabled'] = filter_var( $in['webhook_enabled'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( isset( $in['webhook_url'] ) ) {
			$out['webhook_url'] = esc_url_raw( trim( (string) $in['webhook_url'] ), array( 'http', 'https' ) );
		}
		if ( isset( $in['webhook_method'] ) ) {
			$out['webhook_method'] = self::method( $in['webhook_method'] );
		}
		return array_merge( $out, self::clean_conv( $in ) );
	}

	/** Sanifica i campi delle conversioni presenti in $in. */
	public static function clean_conv( array $in ) {
		$out = array();
		foreach ( array( 'conv_enabled', 'conv_gtm', 'conv_ga4', 'conv_ads', 'conv_meta' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = filter_var( $in[ $k ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		foreach ( array( 'conv_gtm_event', 'conv_ga4_event', 'conv_meta_event' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in[ $k ] ), 0, 40 );
			}
		}
		if ( isset( $in['conv_ads_send_to'] ) ) {
			$out['conv_ads_send_to'] = preg_replace( '#[^A-Za-z0-9_\-/]#', '', (string) $in['conv_ads_send_to'] );
		}
		if ( isset( $in['conv_ads_value'] ) ) {
			$v                     = str_replace( ',', '.', trim( (string) $in['conv_ads_value'] ) );
			$out['conv_ads_value'] = is_numeric( $v ) ? (string) (float) $v : '';
		}
		if ( isset( $in['conv_ads_currency'] ) ) {
			$c                        = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['conv_ads_currency'] ) );
			$out['conv_ads_currency'] = 3 === strlen( $c ) ? $c : 'EUR';
		}
		return $out;
	}

	private static function normalize( array $s ) {
		foreach ( array( 'conv_enabled', 'conv_gtm', 'conv_ga4', 'conv_ads', 'conv_meta' ) as $k ) {
			$s[ $k ] = (bool) $s[ $k ];
		}
		$s['retention_months'] = (int) $s['retention_months'];
		$s['webhook_enabled']  = (bool) $s['webhook_enabled'];
		$s['webhook_method']   = self::method( $s['webhook_method'] );
		return $s;
	}

	public static function method( $m ) {
		$m = strtoupper( (string) $m );
		return in_array( $m, array( 'POST', 'PUT', 'PATCH', 'GET' ), true ) ? $m : 'POST';
	}

	/* ------------------------------------------------------------------ override per form */

	public static function form_defaults() {
		return array(
			'admin'    => array(
				'mode'       => 'elementor', // elementor = override dei campi sull'email di Elementor; lu3g = email LU3G col template
				'to'         => '',
				'subject'    => '',
				'from_email' => '',
				'from_name'  => '',
				'reply_to'   => '',          // con "elementor": id di un campo email del form; con "lu3g": anche un indirizzo o {{campo}}
				'cc'         => '',
				'bcc'        => '',
				'body'       => '',
			),
			'user'     => array(
				'enabled'    => false,
				'to_field'   => '',          // id del campo email di chi compila
				'subject'    => '',
				'from_email' => '',
				'from_name'  => '',
				'reply_to'   => '',
				'body'       => '',
			),
			'redirect' => '',
			'webhook'  => array(
				'mode'   => 'default',       // default = usa quello generale (se attivo), custom = suo, off = nessuno
				'url'    => '',
				'method' => 'POST',
			),
			'conv'     => array(
				'mode' => 'default',         // default = impostazioni generali (se attive), custom = sue, off = nessuna
			),
		);
	}

	public static function form( $key ) {
		$all = get_option( self::FORMS, array() );
		$cfg = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$def = self::form_defaults();
		foreach ( array( 'admin', 'user', 'webhook', 'conv' ) as $group ) {
			$cfg[ $group ] = array_merge( $def[ $group ], isset( $cfg[ $group ] ) && is_array( $cfg[ $group ] ) ? $cfg[ $group ] : array() );
		}
		$cfg['redirect'] = isset( $cfg['redirect'] ) ? (string) $cfg['redirect'] : '';
		return $cfg;
	}

	public static function save_form( $key, array $in ) {
		$all = get_option( self::FORMS, array() );
		$all = is_array( $all ) ? $all : array();

		$a = isset( $in['admin'] ) && is_array( $in['admin'] ) ? $in['admin'] : array();
		$u = isset( $in['user'] ) && is_array( $in['user'] ) ? $in['user'] : array();
		$w = isset( $in['webhook'] ) && is_array( $in['webhook'] ) ? $in['webhook'] : array();
		$c = isset( $in['conv'] ) && is_array( $in['conv'] ) ? $in['conv'] : array();

		$all[ $key ] = array(
			'admin'    => array(
				'mode'       => ( isset( $a['mode'] ) && 'lu3g' === $a['mode'] ) ? 'lu3g' : 'elementor',
				'to'         => self::emails_or_placeholders( $a['to'] ?? '' ),
				'subject'    => sanitize_text_field( $a['subject'] ?? '' ),
				'from_email' => sanitize_email( $a['from_email'] ?? '' ),
				'from_name'  => sanitize_text_field( $a['from_name'] ?? '' ),
				'reply_to'   => sanitize_text_field( $a['reply_to'] ?? '' ),
				'cc'         => self::emails_or_placeholders( $a['cc'] ?? '' ),
				'bcc'        => self::emails_or_placeholders( $a['bcc'] ?? '' ),
				'body'       => self::body( $a['body'] ?? '' ),
			),
			'user'     => array(
				'enabled'    => ! empty( $u['enabled'] ) && 'false' !== $u['enabled'],
				'to_field'   => sanitize_key( $u['to_field'] ?? '' ),
				'subject'    => sanitize_text_field( $u['subject'] ?? '' ),
				'from_email' => sanitize_email( $u['from_email'] ?? '' ),
				'from_name'  => sanitize_text_field( $u['from_name'] ?? '' ),
				'reply_to'   => sanitize_text_field( $u['reply_to'] ?? '' ),
				'body'       => self::body( $u['body'] ?? '' ),
			),
			'redirect' => self::url_with_placeholders( $in['redirect'] ?? '' ),
			'webhook'  => array(
				'mode'   => in_array( $w['mode'] ?? '', array( 'default', 'custom', 'off' ), true ) ? $w['mode'] : 'default',
				'url'    => esc_url_raw( trim( (string) ( $w['url'] ?? '' ) ), array( 'http', 'https' ) ),
				'method' => self::method( $w['method'] ?? 'POST' ),
			),
			'conv'     => array_merge(
				array( 'mode' => in_array( $c['mode'] ?? '', array( 'default', 'custom', 'off' ), true ) ? $c['mode'] : 'default' ),
				self::clean_conv( $c )
			),
		);
		update_option( self::FORMS, $all, false );
		return self::form( $key );
	}

	/**
	 * URL con placeholder ({{nome}}): esc_url_raw() toglierebbe le graffe, quindi si mettono da parte e si rimettono.
	 */
	private static function url_with_placeholders( $url ) {
		$url  = trim( (string) $url );
		$keep = array();
		$url  = preg_replace_callback(
			'/\{\{([a-z0-9_\-]+)\}\}/i',
			function ( $m ) use ( &$keep ) {
				$token          = 'lu3gph' . count( $keep ) . 'x';
				$keep[ $token ] = '{{' . $m[1] . '}}';
				return $token;
			},
			$url
		);
		return strtr( esc_url_raw( $url ), $keep );
	}

	/** Indirizzi separati da virgole, oppure placeholder {{campo}}: il resto si scarta. */
	private static function emails_or_placeholders( $value ) {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) as $part ) {
			if ( is_email( $part ) || preg_match( '/^\{\{[a-z0-9_\-]+\}\}$/i', $part ) ) {
				$out[] = $part;
			}
		}
		return implode( ', ', $out );
	}

	/** Corpo HTML delle email: HTML sicuro (come in un articolo), placeholder lasciati intatti. */
	private static function body( $html ) {
		return wp_kses_post( (string) $html );
	}

	/** Il form ha degli override attivi? (per l'etichetta nell'elenco) */
	public static function has_overrides( $key ) {
		$c = self::form( $key );
		$a = $c['admin'];
		return 'lu3g' === $a['mode'] || '' !== $a['to'] . $a['subject'] . $a['from_email'] . $a['from_name'] . $a['reply_to'] . $a['cc'] . $a['bcc']
			|| $c['user']['enabled'] || '' !== $c['redirect'] || 'default' !== $c['webhook']['mode'];
	}

	/**
	 * Conversioni effettive di un form (null = nessuna): [ gtm => nome evento|'', ga4 => …, ads => [send_to, value, currency]|null, meta => … ].
	 */
	public static function conversions_for( $key ) {
		$c = self::form( $key )['conv'];
		if ( 'off' === $c['mode'] ) {
			return null;
		}
		$g = self::general();
		if ( 'default' === $c['mode'] ) {
			if ( ! $g['conv_enabled'] ) {
				return null;
			}
			$src = $g;
		} else {
			$src = array_merge( $g, array_intersect_key( $c, array_flip( self::conv_keys() ) ) );
		}
		$out = array(
			'gtm'  => ! empty( $src['conv_gtm'] ) ? (string) $src['conv_gtm_event'] : '',
			'ga4'  => ! empty( $src['conv_ga4'] ) ? (string) $src['conv_ga4_event'] : '',
			'ads'  => ! empty( $src['conv_ads'] ) && '' !== $src['conv_ads_send_to'] ? array(
				'send_to'  => (string) $src['conv_ads_send_to'],
				'value'    => '' !== (string) $src['conv_ads_value'] ? (float) $src['conv_ads_value'] : null,
				'currency' => (string) $src['conv_ads_currency'],
			) : null,
			'meta' => ! empty( $src['conv_meta'] ) ? (string) $src['conv_meta_event'] : '',
		);
		return ( '' === $out['gtm'] && '' === $out['ga4'] && null === $out['ads'] && '' === $out['meta'] ) ? null : $out;
	}

	/** Webhook effettivo di un form: [ 'url' => ..., 'method' => ... ] oppure null. */
	public static function webhook_for( $key ) {
		$w = self::form( $key )['webhook'];
		if ( 'off' === $w['mode'] ) {
			return null;
		}
		if ( 'custom' === $w['mode'] ) {
			return '' !== $w['url'] ? array( 'url' => $w['url'], 'method' => $w['method'], 'source' => 'form' ) : null;
		}
		$g = self::general();
		return ( $g['webhook_enabled'] && '' !== $g['webhook_url'] ) ? array( 'url' => $g['webhook_url'], 'method' => $g['webhook_method'], 'source' => 'default' ) : null;
	}

	/**
	 * URL http/https ben formato. Non si usa wp_http_validate_url(): cerca il dominio nel DNS (lento, e scarta i domini
	 * non ancora attivi) e accetta solo le porte 80, 443 e 8080. Qui l'URL lo imposta un amministratore.
	 */
	public static function valid_url( $url ) {
		$url = (string) $url;
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
	}

	/** Elementor Pro abbastanza recente per sostituire le sue email (filtro elementor_pro/forms/submit_actions, 4.0.3). */
	public static function can_replace_emails() {
		return defined( 'ELEMENTOR_PRO_VERSION' ) && version_compare( ELEMENTOR_PRO_VERSION, '4.0.3', '>=' );
	}
}
