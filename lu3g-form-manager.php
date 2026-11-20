<?php
/**
 * Plugin Name:       LU3G Form Manager
 * Plugin URI:        https://github.com/lu3gofficial/lu3g-form-manager
 * Description:       Tutti i form di Elementor Pro in un unico pannello: destinatari, thank you page e webhook senza aprire Elementor, raccolta delle richieste con export CSV, log dei webhook ed email personalizzate con anteprima.
 * Version:           1.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  elementor
 * Author:            LU3G Agenzia Web
 * Author URI:        https://lu3g.it
 * License:           GPL-2.0-or-later
 * Text Domain:       lu3g-form-manager
 * Domain Path:       /languages
 * Update URI:        https://github.com/lu3gofficial/lu3g-form-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LU3G_FM_VERSION', '1.6.0' );
define( 'LU3G_FM_FILE', __FILE__ );
define( 'LU3G_FM_URL', plugin_dir_url( __FILE__ ) );
define( 'LU3G_FM_PATH', plugin_dir_path( __FILE__ ) );

// Gli aggiornamenti da GitHub funzionano sempre, anche senza Elementor Pro: così una correzione arriva comunque.
require_once LU3G_FM_PATH . 'includes/class-updater.php';
LU3G_FM_Updater::init( __FILE__ );

/**
 * Avvio dopo il caricamento dei plugin.
 * - Database, impostazioni, scansione e pannello di rete si caricano sempre: su un Multisite Elementor Pro può essere
 *   attivo solo su alcuni siti, e la rete deve vederli tutti.
 * - L'aggancio ai form (runtime) e il pannello del sito solo dove Elementor Pro è attivo; altrimenti un avviso.
 */
function lu3g_fm_boot() {
	load_plugin_textdomain( 'lu3g-form-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	require_once LU3G_FM_PATH . 'includes/class-db.php';
	require_once LU3G_FM_PATH . 'includes/class-settings.php';
	require_once LU3G_FM_PATH . 'includes/class-scanner.php';
	require_once LU3G_FM_PATH . 'includes/class-emails.php';
	require_once LU3G_FM_PATH . 'includes/class-webhook.php';
	LU3G_FM_DB::init();

	if ( is_multisite() ) {
		require_once LU3G_FM_PATH . 'includes/class-network.php';
		LU3G_FM_Network::init();
	}

	if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
		if ( ! is_network_admin() ) {
			add_action( 'admin_notices', 'lu3g_fm_missing_notice' );
		}
		return;
	}

	require_once LU3G_FM_PATH . 'includes/class-elementor-editor.php';
	require_once LU3G_FM_PATH . 'includes/class-runtime.php';
	require_once LU3G_FM_PATH . 'includes/class-webhook-log.php';
	LU3G_FM_Runtime::init();
	LU3G_FM_Webhook_Log::init();

	if ( is_admin() ) {
		require_once LU3G_FM_PATH . 'includes/class-admin.php';
		LU3G_FM_Admin::init();
	}
}
add_action( 'plugins_loaded', 'lu3g_fm_boot', 20 );

function lu3g_fm_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>LU3G Form Manager</strong> %s</p></div>',
		esc_html__( 'ha bisogno di Elementor Pro attivo: finché non c\'è, il plugin non fa nulla.', 'lu3g-form-manager' )
	);
}

// Attivazione: sul singolo sito crea le sue tabelle; con l'attivazione di rete le crea su tutti i siti.
register_activation_hook(
	__FILE__,
	function ( $network_wide ) {
		require_once LU3G_FM_PATH . 'includes/class-db.php';
		if ( is_multisite() && $network_wide ) {
			LU3G_FM_DB::install_network();
		} else {
			LU3G_FM_DB::install();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'lu3g_fm_retention' );
		wp_clear_scheduled_hook( 'lu3g_fm_network_scan' );
	}
);
