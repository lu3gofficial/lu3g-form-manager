<?php
/**
 * Log delle chiamate del webhook di Elementor (1.4.0).
 *
 * L'azione "Webhook" di Elementor Pro offre due punti (verificato sul codice della 4.4):
 * - il filtro "elementor_pro/forms/webhooks/request_args" subito prima della chiamata: qui si annotano i dati inviati
 *   e l'ora di partenza;
 * - l'azione "elementor_pro/forms/webhooks/response" subito dopo: qui si scrive il log con stato HTTP, risposta
 *   (primi 5000 caratteri), errore e durata.
 * Il webhook resta quello di Elementor: qui lo si osserva soltanto. "Reinvia" rimanda gli stessi dati allo stesso
 * URL nello stesso modo (POST con i campi come form, come fa Elementor).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_FM_Webhook_Log {

	/** Chiamata in corso: [ 'url', 'body', 'start', 'key' ]. */
	private static $pending = null;

	/** Id dell'ultima riga scritta durante l'invio: la richiesta salvata dopo vi aggiunge il suo id. */
	private static $last = 0;

	public static function init() {
		add_filter( 'elementor_pro/forms/webhooks/request_args', array( __CLASS__, 'before' ), 9999, 2 );
		add_action( 'elementor_pro/forms/webhooks/response', array( __CLASS__, 'after' ), 10, 2 );
	}

	public static function before( $args, $record ) {
		try {
			$s               = (array) $record->get( 'form_settings' );
			self::$pending = array(
				'url'   => (string) ( $s['webhooks'] ?? '' ),
				'body'  => $args['body'] ?? array(),
				'start' => microtime( true ),
				'key'   => LU3G_FM_Runtime::key_of( $record ),
			);
		} catch ( \Throwable $e ) {
			self::$pending = null;
		}
		return $args;
	}

	public static function after( $response, $record ) {
		if ( ! self::$pending ) {
			return;
		}
		$p             = self::$pending;
		self::$pending = null;
		$code          = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		self::$last    = self::write( $p['key'], $p['url'], $p['body'], $response, (int) round( ( microtime( true ) - $p['start'] ) * 1000 ), 0 );
		unset( $code );
	}

	/** Dopo il salvataggio della richiesta: collega l'ultima chiamata alla richiesta. */
	public static function attach_submission( $submission_id ) {
		if ( self::$last && $submission_id ) {
			global $wpdb;
			$wpdb->update( LU3G_FM_DB::webhook_log(), array( 'submission_id' => (int) $submission_id ), array( 'id' => self::$last ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::$last = 0;
		}
	}

	private static function write( $key, $url, $body, $response, $ms, $submission_id ) {
		$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$error = is_wp_error( $response ) ? $response->get_error_message() : ( ( $code < 200 || $code >= 300 ) ? sprintf( 'HTTP %d', $code ) : '' );
		return LU3G_FM_DB::insert_webhook_log(
			array(
				'form_key'      => $key,
				'submission_id' => (int) $submission_id,
				'url'           => $url,
				'method'        => 'POST',
				'payload'       => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status_code'   => $code,
				'response'      => is_wp_error( $response ) ? '' : mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 5000 ),
				'error'         => '' !== $error ? $error : null,
				'duration_ms'   => $ms,
				'is_test'       => 0,
				'source'        => 'elementor',
				'created'       => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Rimanda una chiamata del webhook di Elementor con gli stessi dati, all'URL di allora.
	 *
	 * @return array|WP_Error Riga nuova del log.
	 */
	public static function resend( array $log ) {
		$body = json_decode( (string) $log['payload'], true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'lu3g_fm_nopayload', __( 'I dati di questa chiamata non sono leggibili.', 'lu3g-form-manager' ) );
		}
		$start = microtime( true );
		$res   = wp_safe_remote_post( $log['url'], array( 'body' => $body, 'timeout' => 15 ) );
		$id    = self::write( $log['form_key'], $log['url'], $body, $res, (int) round( ( microtime( true ) - $start ) * 1000 ), (int) $log['submission_id'] );
		return LU3G_FM_DB::get_webhook_log( $id );
	}
}
