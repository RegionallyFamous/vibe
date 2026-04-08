<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs + plugin includes.
 *
 * @package VibeCheck
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/stubs-wordpress.php';
require_once __DIR__ . '/stubs-github-updater.php';
require_once dirname( __DIR__ ) . '/github-updater.php';
require_once dirname( __DIR__ ) . '/includes/class-vibe-check-quiz-data.php';
