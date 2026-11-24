<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
$moshcw_settings = get_option( 'moshcw_settings', array() );
if ( empty( $moshcw_settings['clean_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'logs', 'discussions', 'vectors' ) as $moshcw_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}moshcw_{$moshcw_table}" ); // phpcs:ignore
}
foreach ( array( 'moshcw_settings', 'moshcw_chatbots', 'moshcw_forms', 'moshcw_db_version', 'moshcw_models_cache', 'moshcw_kb_synced' ) as $moshcw_option ) {
	delete_option( $moshcw_option );
}
delete_post_meta_by_key( '_moshcw_meta_description' );
wp_clear_scheduled_hook( 'moshcw_daily' );
