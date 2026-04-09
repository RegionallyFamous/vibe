<?php
/**
 * GitHub release API token: wp-config constant or Settings → Vibe Check.
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option: PAT for private forks / rate limits (plain text in DB; use wp-config when possible). */
const VIBE_CHECK_OPTION_GITHUB_UPDATER_TOKEN = 'vibe_check_github_updater_token';

/**
 * Bearer token for GitHub Releases API. Constant {@see GITHUB_UPDATER_TOKEN} wins over the saved option.
 *
 * @return string
 */
function vibe_check_get_github_updater_token() {
	if ( defined( 'GITHUB_UPDATER_TOKEN' ) && (string) GITHUB_UPDATER_TOKEN !== '' ) {
		$t = trim( (string) GITHUB_UPDATER_TOKEN );
		return strlen( $t ) > 512 ? substr( $t, 0, 512 ) : $t;
	}
	$stored = get_option( VIBE_CHECK_OPTION_GITHUB_UPDATER_TOKEN, '' );
	if ( ! is_string( $stored ) || '' === $stored ) {
		return '';
	}
	$t = trim( $stored );
	return strlen( $t ) > 512 ? substr( $t, 0, 512 ) : $t;
}

/**
 * Drop cached /releases/latest JSON so a new token takes effect immediately.
 *
 * @return void
 */
function vibe_check_clear_github_release_cache() {
	if ( ! class_exists( \RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface::class, false ) ) {
		return;
	}
	$owner = defined( 'VIBE_CHECK_GITHUB_OWNER' ) ? (string) VIBE_CHECK_GITHUB_OWNER : 'RegionallyFamous';
	$repo  = defined( 'VIBE_CHECK_GITHUB_REPO' ) ? (string) VIBE_CHECK_GITHUB_REPO : 'vibe';
	delete_site_transient( \RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface::release_transient_key( $owner, $repo ) );
	\RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface::clear_static_memo();
}

/**
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New value.
 * @return void
 */
function vibe_check_on_github_updater_token_updated( $old_value, $value ) {
	if ( (string) $old_value === (string) $value ) {
		return;
	}
	vibe_check_clear_github_release_cache();
}

add_action( 'update_option_' . VIBE_CHECK_OPTION_GITHUB_UPDATER_TOKEN, 'vibe_check_on_github_updater_token_updated', 10, 2 );
