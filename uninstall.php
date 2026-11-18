<?php
/**
 * Eliminazione di LU3G Form Manager (Plugin → Elimina): tabelle e opzioni di ogni sito, e opzioni di rete.
 * Disattivare non cancella niente: solo l'eliminazione.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function lu3g_fm_uninstall_site() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lu3g_fm_submissions, {$wpdb->prefix}lu3g_fm_webhook_log" );
	foreach ( array( 'lu3g_fm_db', 'lu3g_fm_site', 'lu3g_fm_forms_cfg', 'lu3g_fm_forms' ) as $option ) {
		delete_option( $option );
	}
	wp_clear_scheduled_hook( 'lu3g_fm_retention' );
	wp_clear_scheduled_hook( 'lu3g_fm_network_scan' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $lu3g_fm_site ) {
		switch_to_blog( $lu3g_fm_site );
		lu3g_fm_uninstall_site();
		restore_current_blog();
	}
	delete_site_option( 'lu3g_fm_network' );
	delete_site_option( 'lu3g_fm_network_locks' );
} else {
	lu3g_fm_uninstall_site();
}
