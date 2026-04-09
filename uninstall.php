<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package VibeCheck
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Plugin transients (OG rate limits, quiz attrs/sanitized cache, Claude cache, GitHub updater release cache).
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vibe_check_%' OR option_name LIKE '_transient_timeout_vibe_check_%' OR option_name LIKE '_transient_ghu_%' OR option_name LIKE '_transient_timeout_ghu_%'"
);

delete_option( 'vibe_check_claude_api_key' );
delete_option( 'vibe_check_claude_system_addendum' );
delete_option( 'vibe_check_default_share_image_id' );
delete_option( 'vibe_check_github_updater_token' );
