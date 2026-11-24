<?php
/**
 * Plugin Name:       Mosh AI: Chatbot & Content Writer
 * Plugin URI:        https://github.com/samayunme/mosh-ai
 * Description:       AI chatbots trained on your content, an AI writer for posts and products, AI images, AI forms and a knowledge base. Works with OpenAI, Anthropic (Claude), Google Gemini, OpenRouter and any OpenAI-compatible API.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WEBMOSH
 * Author URI:        https://webmosh.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mosh-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MOSHCW_VERSION', '1.0.0' );
define( 'MOSHCW_FILE', __FILE__ );
define( 'MOSHCW_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOSHCW_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'kses', 'settings', 'db', 'providers', 'core', 'chatbots', 'forms', 'rest', 'editor', 'admin' ) as $moshcw_part ) {
	require_once MOSHCW_DIR . 'includes/class-' . $moshcw_part . '.php';
}
unset( $moshcw_part );

register_activation_hook( __FILE__, array( 'MoshCW_DB', 'install' ) );
register_deactivation_hook( __FILE__, 'moshcw_deactivate' );

function moshcw_deactivate() {
	wp_clear_scheduled_hook( 'moshcw_daily' );
	wp_unschedule_hook( 'moshcw_kb_sync_post' );
}

add_action( 'plugins_loaded', 'moshcw_boot' );

function moshcw_boot() {
	MoshCW_DB::maybe_upgrade();
	MoshCW_Core::init();
	MoshCW_Chatbots::init();
	MoshCW_Forms::init();
	MoshCW_REST::init();
	MoshCW_Editor::init();
	add_action( 'admin_bar_menu', array( 'MoshCW_Admin', 'admin_bar' ), 90 );
	if ( is_admin() ) {
		MoshCW_Admin::init();
	}
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'moshcw_action_links' );

function moshcw_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=moshcw-settings' ) ) . '">' . esc_html__( 'Settings', 'mosh-ai' ) . '</a>' );
	return $links;
}
