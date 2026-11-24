<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoshCW_DB {

	const VERSION = '1.0';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'moshcw_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$logs    = self::table( 'logs' );
		$disc    = self::table( 'discussions' );
		$vec     = self::table( 'vectors' );

		dbDelta(
			"CREATE TABLE $logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			time datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash varchar(64) NOT NULL DEFAULT '',
			source varchar(40) NOT NULL DEFAULT '',
			ref varchar(64) NOT NULL DEFAULT '',
			provider varchar(30) NOT NULL DEFAULT '',
			model varchar(120) NOT NULL DEFAULT '',
			tokens_in int(11) unsigned NOT NULL DEFAULT 0,
			tokens_out int(11) unsigned NOT NULL DEFAULT 0,
			units int(11) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY time (time),
			KEY user_id (user_id),
			KEY ip_hash (ip_hash),
			KEY ip_time (ip_hash,time),
			KEY user_time (user_id,time)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $disc (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chat_id varchar(64) NOT NULL,
			bot_id varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash varchar(64) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			messages longtext NOT NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY chat_id (chat_id),
			KEY bot_id (bot_id),
			KEY updated (updated)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $vec (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			content longtext NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			embedding longtext NOT NULL,
			norm double NOT NULL DEFAULT 0,
			model varchar(120) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id)
			) $charset;"
		);

		update_option( 'moshcw_db_version', self::VERSION, false );

		if ( ! get_option( 'moshcw_chatbots' ) ) {
			update_option( 'moshcw_chatbots', array( 'default' => MoshCW_Chatbots::defaults( 'default' ) ), false );
		}
		if ( ! get_option( 'moshcw_forms' ) ) {
			update_option( 'moshcw_forms', array( 'idea-generator' => MoshCW_Forms::defaults( 'idea-generator' ) ), false );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'moshcw_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}
}
