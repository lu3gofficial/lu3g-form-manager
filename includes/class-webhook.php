<?php
/**
 * Webhook "stile SaveMyLeads": a ogni invio i dati del form partono in JSON verso l'URL configurato.
 *
 * Payload:
 *   {
 *     "event": "form_submission", "submission_id": 12, "test": false,
 *     "form": { "key": "45:a1b2c3", "name": "Contatti", "form_id": "", "element_id": "a1b2c3", "post_id": 45 },
 *     "fields": { "nome": "Mario", "email": "mario@..." },               // per id del campo
 *     "fields_detail": [ { "id": "nome", "label": "Nome", "type": "text", "value": "Mario" } ],
 *     "page": { "url": "https://...", "title": "Contatti" },
 *     "submitted_at": "2026-11-18T10:30:00+01:00", "timestamp": 1795...,
 *     "ip": "...", "user_agent": "...", "site": { "name": "...", "url": "https://..." }
 *   }
 * Con il metodo GET i campi vanno nella query string (fields piatti + form_key, page_url, submitted_at).
 * Ogni chiamata finisce nel log: stato HTTP, risposta (primi 5000 caratteri), errore, durata. Timeout 8 secondi.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Webhook {

	const TIMEOUT = 8;

	public static function payload( array $ctx, $submission_id = 0, $test = false ) {
		$f      = $ctx['form'];
		$m      = $ctx['meta'];
		$flat   = array();
		$detail = array();
		foreach ( $ctx['fields'] as $field ) {
			$flat[ $field['id'] ] = $field['value'];
			$detail[]             = array(
				'id'    => $field['id'],
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => $field['value'],
			);
		}
		return array(
			'event'         => 'form_submission',
			'submission_id' => (int) $submission_id,
			'test'          => (bool) $test,
			'form'          => array(
				'key'        => (string) ( $f['key'] ?? '' ),
				'name'       => (string) ( $f['name'] ?? '' ),
				'form_id'    => (string) ( $f['form_id'] ?? '' ),
				'element_id' => (string) ( $f['element_id'] ?? '' ),
				'post_id'    => (int) ( $f['post_id'] ?? 0 ),
			),
			'fields'        => $flat,
			'fields_detail' => $detail,
			'page'          => array(
				'url'   => (string) $m['page_url'],
				'title' => (string) $m['page_title'],
			),
			'submitted_at'  => wp_date( 'c', $m['time'] ),
			'timestamp'     => (int) $m['time'],
			'ip'            => (string) $m['ip'],
			'user_agent'    => (string) $m['user_agent'],
			'site'          => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
		);
	}

	/**
	 * Invia il payload e scrive il log.
	 *
	 * @return array Riga del log (id, status_code, error, response).
	 */
	public static function send( $url, $method, array $payload, $form_key, $submission_id = 0, $test = false ) {
		$method = LU3G_FM_Settings::method( $method );
		$args   = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 3,
			'user-agent'  => 'LU3G-Form-Manager/' . LU3G_FM_VERSION . '; ' . home_url( '/' ),
			'headers'     => array(
				'Content-Type'     => 'application/json; charset=utf-8',
				'Accept'           => 'application/json, */*',
				'X-LU3G-Event'     => 'form_submission',
				'X-LU3G-Form'      => $form_key,
			),
		);
		$target = $url;
		if ( 'GET' === $method ) {
			$query  = array_merge(
				array_map(
					function ( $v ) {
						return is_array( $v ) ? implode( ', ', $v ) : $v;
					},
					$payload['fields']
				),
				array(
					'form_key'     => $payload['form']['key'],
					'form_name'    => $payload['form']['name'],
					'page_url'     => $payload['page']['url'],
					'submitted_at' => $payload['submitted_at'],
				)
			);
			$target = add_query_arg( rawurlencode_deep( $query ), $url );
			unset( $args['headers']['Content-Type'] );
		} else {
			$args['body'] = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$start = microtime( true );
		$res   = wp_remote_request( $target, $args ); // URL scelto da un amministratore: ogni porta è ammessa
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );

		$code  = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$error = is_wp_error( $res ) ? $res->get_error_message() : ( ( $code < 200 || $code >= 300 ) ? sprintf( 'HTTP %d', $code ) : '' );
		$body  = is_wp_error( $res ) ? '' : mb_substr( (string) wp_remote_retrieve_body( $res ), 0, 5000 );

		$row = array(
			'form_key'      => $form_key,
			'submission_id' => (int) $submission_id,
			'url'           => $url,
			'method'        => $method,
			'payload'       => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'status_code'   => $code,
			'response'      => $body,
			'error'         => '' !== $error ? $error : null,
			'duration_ms'   => $ms,
			'is_test'       => $test ? 1 : 0,
			'created'       => current_time( 'mysql', true ),
		);
		$row['id'] = LU3G_FM_DB::insert_webhook_log( $row );
		return $row;
	}

	/** Rimanda una chiamata del log con lo stesso payload all'URL attuale del form (o a quello di allora). */
	public static function resend( $log_id ) {
		$log = LU3G_FM_DB::get_webhook_log( $log_id );
		if ( ! $log ) {
			return new WP_Error( 'lu3g_fm_nolog', __( 'Chiamata non trovata nel log.', 'lu3g-form-manager' ) );
		}
		$payload = json_decode( (string) $log['payload'], true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'lu3g_fm_nopayload', __( 'Il payload di questa chiamata non è leggibile.', 'lu3g-form-manager' ) );
		}
		$hook = LU3G_FM_Settings::webhook_for( $log['form_key'] );
		$url  = $hook ? $hook['url'] : $log['url'];
		$meth = $hook ? $hook['method'] : $log['method'];
		return self::send( $url, $meth, $payload, $log['form_key'], (int) $log['submission_id'], (bool) $log['is_test'] );
	}

	/** Invio di prova con dati di esempio, all'URL indicato (o a quello del form). */
	public static function test( array $form, $url = '', $method = '' ) {
		$hook = LU3G_FM_Settings::webhook_for( $form['key'] );
		$url  = '' !== $url ? $url : ( $hook ? $hook['url'] : '' );
		if ( ! LU3G_FM_Settings::valid_url( $url ) ) {
			return new WP_Error( 'lu3g_fm_nourl', __( 'Inserisci un URL valido (http o https).', 'lu3g-form-manager' ) );
		}
		$method = '' !== $method ? $method : ( $hook ? $hook['method'] : 'POST' );
		return self::send( $url, $method, self::payload( LU3G_FM_Emails::sample_context( $form ), 0, true ), $form['key'], 0, true );
	}
}
