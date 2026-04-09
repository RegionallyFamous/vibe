<?php
/**
 * Loads {@see \RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface} for Vibe Check.
 *
 * The implementation lives in packages/updater-mcupdateface (Composer package: updater-mcupdateface).
 * The release zip includes that path so installs work without running Composer.
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Avoid "Cannot declare class … already in use" when Composer (or another plugin) autoloaded
// this same class from vendor while a second copy exists under packages/ (different realpath).
if ( ! class_exists( \RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface::class, false ) ) {
	$rf_ghu_paths = array(
		__DIR__ . '/vendor/regionallyfamous/updater-mcupdateface/src/UpdaterMcUpdateface.php',
		__DIR__ . '/packages/updater-mcupdateface/src/UpdaterMcUpdateface.php',
	);

	foreach ( $rf_ghu_paths as $rf_ghu_file ) {
		if ( is_readable( $rf_ghu_file ) ) {
			require_once $rf_ghu_file;
			break;
		}
	}
}

if ( ! class_exists( \RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface::class, false ) ) {
	return;
}

if ( ! class_exists( 'GitHub_Plugin_Updater', false ) ) {
	class_alias( \RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface::class, 'GitHub_Plugin_Updater' );
}
