<?php
/**
 * Loads {@see \RegionallyFamous\WpGithubReleaseUpdater\GitHub_Plugin_Updater} for Vibe Check.
 *
 * The implementation lives in packages/wp-github-release-updater (Composer package). The release
 * zip includes that path so installs work without running Composer.
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rf_ghu_paths = array(
	__DIR__ . '/vendor/regionallyfamous/wp-github-release-updater/src/GitHub_Plugin_Updater.php',
	__DIR__ . '/packages/wp-github-release-updater/src/GitHub_Plugin_Updater.php',
);

foreach ( $rf_ghu_paths as $rf_ghu_file ) {
	if ( is_readable( $rf_ghu_file ) ) {
		require_once $rf_ghu_file;
		break;
	}
}

if ( ! class_exists( \RegionallyFamous\WpGithubReleaseUpdater\GitHub_Plugin_Updater::class, false ) ) {
	return;
}

if ( ! class_exists( 'GitHub_Plugin_Updater', false ) ) {
	class_alias( \RegionallyFamous\WpGithubReleaseUpdater\GitHub_Plugin_Updater::class, 'GitHub_Plugin_Updater' );
}
