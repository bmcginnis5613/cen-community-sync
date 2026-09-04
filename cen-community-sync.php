<?php
/**
 * Plugin Name: CEN Community Sync
 * Description: Matches source-site WordPress users by email and syncs selected ACF fields.
 * Version: 1.5.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: CEN
 * License: GPL-2.0-or-later
 * Text Domain: cen-community-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEN_COMMUNITY_SYNC_VERSION', '1.5.2' );
define( 'CEN_COMMUNITY_SYNC_FILE', __FILE__ );
define( 'CEN_COMMUNITY_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once CEN_COMMUNITY_SYNC_DIR . 'includes/class-cen-community-sync-settings.php';
require_once CEN_COMMUNITY_SYNC_DIR . 'includes/class-cen-community-sync-api-client.php';
require_once CEN_COMMUNITY_SYNC_DIR . 'includes/class-cen-community-sync-service.php';
require_once CEN_COMMUNITY_SYNC_DIR . 'includes/class-cen-community-sync-logger.php';
require_once CEN_COMMUNITY_SYNC_DIR . 'includes/class-cen-community-sync-scheduler.php';

register_activation_hook(
	__FILE__,
	static function () {
		CEN_Community_Sync_Settings::activate();
		CEN_Community_Sync_Logger::activate();
		CEN_Community_Sync_Scheduler::activate();
	}
);

register_deactivation_hook( __FILE__, array( 'CEN_Community_Sync_Scheduler', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		CEN_Community_Sync_Settings::init();
		CEN_Community_Sync_Scheduler::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once CEN_COMMUNITY_SYNC_DIR . 'includes/class-cen-community-sync-cli-command.php';
			WP_CLI::add_command( 'cen-community', 'CEN_Community_Sync_CLI_Command' );
		}
	}
);
