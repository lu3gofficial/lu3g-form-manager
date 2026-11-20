<?php
/**
 * Email Admin ed Email Utente personalizzate.
 *
 * - Placeholder: {{id_campo}} per ogni campo del form, più {{all_fields}}, {{form_name}}, {{page_url}}, {{page_title}},
 *   {{date}}, {{time}}, {{site_name}}, {{ip}}. Nel corpo HTML i valori sono sempre escapati; {{all_fields}} è una
 *   tabella con etichetta e valore di ogni campo.
 * - Template: cornice HTML con logo, colore principale e footer presi dalle impostazioni (rete → sito).
 * - L'anteprima usa gli stessi passaggi dell'invio vero, con dati di esempio ricavati dai campi del form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Emails {

	/** Placeholder disponibili per un form: [ '{{nome}}' => 'Etichetta' ]. */
	public static function placeholders( $form ) {
		$out = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			$out[ '{{' . $f['id'] . '}}' ] = $f['label'];
		}
		return array_merge(
			$out,
			array(
				'{{all_fields}}' => __( 'Tutti i campi', 'lu3g-form-manager' ),
				'{{form_name}}'  => __( 'Nome del form', 'lu3g-form-manager' ),
				'{{page_url}}'   => __( 'Indirizzo della pagina', 'lu3g-form-manager' ),
				'{{page_title}}' => __( 'Titolo della pagina', 'lu3g-form-manager' ),
				'{{date}}'       => __( 'Data', 'lu3g-form-manager' ),
				'{{time}}'       => __( 'Ora', 'lu3g-form-manager' ),
				'{{site_name}}'  => __( 'Nome del sito', 'lu3g-form-manager' ),
				'{{ip}}'         => __( 'IP di chi ha compilato', 'lu3g-form-manager' ),
			)
		);
	}

	/**
	 * Dati di un invio nel formato usato da email e webhook.
	 * $fields: [ [ 'id' =>, 'label' =>, 'type' =>, 'value' => ] ]; $meta: page_url, page_title, ip, user_agent, time (timestamp).
	 */
	public static function context( array $form, array $fields, array $meta ) {
		$ts = isset( $meta['time'] ) ? (int) $meta['time'] : time();
		return array(
			'form'   => $form,
			'fields' => $fields,
			'meta'   => array_merge(
				array(
					'page_url'   => '',
					'page_title' => '',
					'ip'         => '',
					'user_agent' => '',
				),
				$meta,
				array( 'time' => $ts )
			),
		);
	}

	/** Dati di esempio per l'anteprima: un valore credibile per ogni tipo di campo. */
	public static function sample_context( array $form ) {
		$samples = array(
			'email'    => 'mario.rossi@esempio.it',
			'tel'      => '+39 333 123 4567',
			'textarea' => "Buongiorno, vorrei ricevere maggiori informazioni.\nGrazie.",
			'number'   => '3',
			'date'     => wp_date( 'Y-m-d' ),
			'time'     => '10:30',
			'url'      => 'https://www.esempio.it',
			'select'   => 'Opzione 1',
			'radio'    => 'Opzione 1',
			'checkbox' => 'Sì',
			'acceptance' => 'on',
			'upload'   => 'https://www.esempio.it/wp-content/uploads/documento.pdf',
		);
		$fields = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			$v = $samples[ $f['type'] ] ?? '';
			if ( '' === $v ) {
				$v = false !== stripos( $f['id'] . $f['label'], 'nom' ) ? 'Mario Rossi' : ( false !== stripos( $f['id'] . $f['label'], 'azien' ) ? 'Rossi Srl' : 'Testo di esempio' );
			}
			$fields[] = $f + array( 'value' => $v );
		}
		$post_id = (int) ( $form['post_id'] ?? 0 );
		return self::context(
			$form,
			$fields,
			array(
				'page_url'   => $post_id ? (string) get_permalink( $post_id ) : home_url( '/' ),
				'page_title' => $post_id ? get_the_title( $post_id ) : get_bloginfo( 'name' ),
				'ip'         => '203.0.113.10',
				'user_agent' => 'Mozilla/5.0 (anteprima)',
			)
		);
	}

	/** Mappa placeholder => valore (testo semplice, non escapato). */
	private static function values( array $ctx ) {
		$m   = $ctx['meta'];
		$map = array();
		foreach ( $ctx['fields'] as $f ) {
			$map[ '{{' . $f['id'] . '}}' ] = is_array( $f['value'] ) ? implode( ', ', $f['value'] ) : (string) $f['value'];
		}
		$map['{{form_name}}']  = (string) ( $ctx['form']['name'] ?? '' );
		$map['{{page_url}}']   = (string) $m['page_url'];
		$map['{{page_title}}'] = (string) $m['page_title'];
		$map['{{date}}']       = wp_date( get_option( 'date_format' ), $m['time'] );
		$map['{{time}}']       = wp_date( get_option( 'time_format' ), $m['time'] );
		$map['{{site_name}}']  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$map['{{ip}}']         = (string) $m['ip'];
		return $map;
	}

	/** Sostituisce i placeholder in un testo semplice (oggetto, indirizzi). I placeholder sconosciuti spariscono. */
	public static function replace_text( $text, array $ctx ) {
		$map             = self::values( $ctx );
		$map['{{all_fields}}'] = self::all_fields_text( $ctx );
		$out             = strtr( (string) $text, $map );
		return trim( preg_replace( '/\{\{[a-z0-9_\-]+\}\}/i', '', $out ) );
	}

	/** Sostituisce i placeholder in un corpo HTML, escapando i valori. */
	public static function replace_html( $html, array $ctx ) {
		$map = array();
		foreach ( self::values( $ctx ) as $k => $v ) {
			$map[ $k ] = nl2br( esc_html( $v ) );
		}
		$map['{{all_fields}}'] = self::all_fields_html( $ctx );
		$out                   = strtr( (string) $html, $map );
		return preg_replace( '/\{\{[a-z0-9_\-]+\}\}/i', '', $out );
	}

	private static function all_fields_text( array $ctx ) {
		$lines = array();
		foreach ( $ctx['fields'] as $f ) {
			$v       = is_array( $f['value'] ) ? implode( ', ', $f['value'] ) : (string) $f['value'];
			$lines[] = $f['label'] . ': ' . $v;
		}
		return implode( "\n", $lines );
	}

	private static function all_fields_html( array $ctx ) {
		$color = self::template()['color'];
		$rows  = '';
		foreach ( $ctx['fields'] as $f ) {
			$v     = is_array( $f['value'] ) ? implode( ', ', $f['value'] ) : (string) $f['value'];
			$rows .= '<tr><td style="padding:10px 12px;border-bottom:1px solid #eeeeee;color:#71717a;font-size:13px;width:35%;vertical-align:top">' . esc_html( $f['label'] ) . '</td>'
				. '<td style="padding:10px 12px;border-bottom:1px solid #eeeeee;color:#18181b;font-size:14px;vertical-align:top">' . nl2br( esc_html( $v ) ) . '</td></tr>';
		}
		return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;border-top:3px solid ' . esc_attr( $color ) . '">' . $rows . '</table>';
	}

	/** Impostazioni del template (logo, colore, footer), già risolte rete → sito. */
	public static function template() {
		$g = LU3G_FM_Settings::general();
		return array(
			'logo'   => $g['logo'],
			'color'  => $g['color'] ? $g['color'] : '#09090b',
			'footer' => $g['footer'],
		);
	}

	/** Corpo predefinito quando l'override del corpo è vuoto. */
	public static function default_body( $kind ) {
		if ( 'user' === $kind ) {
			return '<h2 style="margin:0 0 12px">Grazie, abbiamo ricevuto la tua richiesta</h2><p>Ti risponderemo il prima possibile. Ecco un riepilogo di quello che ci hai scritto:</p>{{all_fields}}';
		}
		return '<h2 style="margin:0 0 12px">Nuova richiesta da {{form_name}}</h2><p>Inviata il {{date}} alle {{time}} dalla pagina <a href="{{page_url}}">{{page_title}}</a>.</p>{{all_fields}}';
	}

	/**
	 * Email completa: corpo con i placeholder sostituiti dentro la cornice del template.
	 *
	 * @param array|null $tpl Template da usare (anteprima delle impostazioni non ancora salvate).
	 */
	public static function render( $body, array $ctx, $tpl = null ) {
		$tpl   = $tpl ? $tpl : self::template();
		$color = sanitize_hex_color( $tpl['color'] ) ? $tpl['color'] : '#09090b';
		$inner = self::replace_html( '' !== trim( (string) $body ) ? $body : self::default_body( 'admin' ), $ctx );
		$logo  = '' !== $tpl['logo'] ? '<img src="' . esc_url( $tpl['logo'] ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="display:block;max-width:180px;max-height:60px;height:auto;border:0">' : '<span style="color:#ffffff;font-size:18px;font-weight:bold">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
		$foot  = '' !== trim( (string) $tpl['footer'] ) ? wp_kses_post( self::replace_html( $tpl['footer'], $ctx ) ) : esc_html( get_bloginfo( 'name' ) ) . ' · ' . esc_html( home_url() );

		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f5">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f4f4f5"><tr><td align="center" style="padding:28px 12px">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#18181b">'
			. '<tr><td style="background:' . esc_attr( $color ) . ';padding:22px 28px">' . $logo . '</td></tr>'
			. '<tr><td style="padding:28px;font-size:15px;line-height:1.6">' . $inner . '</td></tr>'
			. '<tr><td style="padding:18px 28px;background:#fafafa;border-top:1px solid #eeeeee;color:#71717a;font-size:12px;line-height:1.5">' . $foot . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Invia un'email LU3G.
	 *
	 * @param array $email to, subject, body, from_email, from_name, reply_to, cc, bcc (con placeholder).
	 * @return true|WP_Error
	 */
	public static function send( array $email, array $ctx, $kind = 'admin' ) {
		$to = self::addresses( $email['to'] ?? '', $ctx );
		if ( ! $to ) {
			return new WP_Error( 'lu3g_fm_no_to', __( 'Nessun destinatario valido.', 'lu3g-form-manager' ) );
		}
		$subject = self::replace_text( '' !== ( $email['subject'] ?? '' ) ? $email['subject'] : ( 'user' === $kind ? 'Abbiamo ricevuto la tua richiesta' : 'Nuova richiesta da {{form_name}}' ), $ctx );
		$body    = '' !== trim( (string) ( $email['body'] ?? '' ) ) ? $email['body'] : self::default_body( $kind );
		$html    = self::render( $body, $ctx );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = self::replace_text( $email['from_email'] ?? '', $ctx );
		if ( is_email( $from ) ) {
			$name      = self::replace_text( $email['from_name'] ?? '', $ctx );
			$headers[] = 'From: ' . ( '' !== $name ? '"' . str_replace( '"', '', $name ) . '" ' : '' ) . '<' . $from . '>';
		}
		foreach ( self::addresses( $email['reply_to'] ?? '', $ctx ) as $r ) {
			$headers[] = 'Reply-To: ' . $r;
		}
		foreach ( self::addresses( $email['cc'] ?? '', $ctx ) as $c ) {
			$headers[] = 'Cc: ' . $c;
		}
		foreach ( self::addresses( $email['bcc'] ?? '', $ctx ) as $b ) {
			$headers[] = 'Bcc: ' . $b;
		}

		$error = null;
		$catch = function ( $e ) use ( &$error ) {
			$error = $e;
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'wp_mail_failed', $catch );

		return $ok ? true : ( $error instanceof WP_Error ? $error : new WP_Error( 'lu3g_fm_mail', __( 'Invio non riuscito.', 'lu3g-form-manager' ) ) );
	}

	/** Indirizzi validi da un elenco con virgole e placeholder ({{email}} o l'id di un campo). */
	public static function addresses( $list, array $ctx ) {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $list ) ) ) as $part ) {
			// Solo l'id di un campo (es. "email"), come lo salva Elementor per "Rispondi a".
			if ( ! is_email( $part ) && preg_match( '/^[a-z0-9_\-]+$/i', $part ) ) {
				$part = '{{' . $part . '}}';
			}
			$v = self::replace_text( $part, $ctx );
			if ( is_email( $v ) ) {
				$out[] = $v;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
