<?php
/**
 * Plugin Name:       Vibe Check
 * Plugin URI:        https://wordpress.org/plugins/vibe-check/
 * Description:       Personality-style quiz block with shareable result cards and optional OG images.
 * Version:           1.5.21
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Vibe Check
 * Author URI:        https://profiles.wordpress.org/vibecheck/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vibe-check
 * Domain Path:       /languages
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VIBE_CHECK_VERSION', '1.5.21' );
define( 'VIBE_CHECK_PLUGIN_FILE', __FILE__ );
define( 'VIBE_CHECK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIBE_CHECK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VIBE_CHECK_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once VIBE_CHECK_PLUGIN_DIR . 'includes/class-vibe-check-quiz-data.php';
require_once VIBE_CHECK_PLUGIN_DIR . 'includes/class-vibe-check-mlp.php';
require_once VIBE_CHECK_PLUGIN_DIR . 'includes/class-vibe-check-claude.php';
require_once VIBE_CHECK_PLUGIN_DIR . 'includes/class-vibe-check-og.php';

/**
 * Load translations (PHP strings in render.php, editor, etc.).
 */
function vibe_check_load_textdomain() {
	load_plugin_textdomain(
		'vibe-check',
		false,
		dirname( VIBE_CHECK_PLUGIN_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'vibe_check_load_textdomain', 0 );

/**
 * Registers the block using the metadata loaded from block.json.
 */
function vibe_check_register_block() {
	register_block_type( VIBE_CHECK_PLUGIN_DIR . 'build' );
}
add_action( 'init', 'vibe_check_register_block' );

/**
 * Allow translated strings in the front-end view script (wp-i18n).
 */
function vibe_check_set_view_script_translations() {
	if ( ! function_exists( 'wp_set_script_translations' ) ) {
		return;
	}
	$registry = WP_Block_Type_Registry::get_instance();
	$block    = $registry->get_registered( 'vibe-check/quiz' );
	if ( ! $block || empty( $block->view_script_handles ) ) {
		return;
	}
	foreach ( $block->view_script_handles as $handle ) {
		wp_set_script_translations(
			$handle,
			'vibe-check',
			VIBE_CHECK_PLUGIN_DIR . 'languages'
		);
	}
}
add_action( 'init', 'vibe_check_set_view_script_translations', 20 );

/**
 * Localize invitation lines and hashtags for front-end share copy (see vibe_check_share_strings).
 */
function vibe_check_localize_view_share_strings() {
	$registry = WP_Block_Type_Registry::get_instance();
	$block    = $registry->get_registered( 'vibe-check/quiz' );
	if ( ! $block || empty( $block->view_script_handles ) ) {
		return;
	}

	$defaults = array(
		'invitationShort' => __( 'Take the quiz—what’s your result?', 'vibe-check' ),
		'invitationLong'  => __( 'Take the quiz and compare your result!', 'vibe-check' ),
		'hashtags'        => __( '#quiz', 'vibe-check' ),
	);
	$defaults['hashtags'] = apply_filters( 'vibe_check_share_hashtags', $defaults['hashtags'] );
	$strings              = apply_filters( 'vibe_check_share_strings', $defaults );

	$out = array(
		'invitationShort' => isset( $strings['invitationShort'] ) ? (string) $strings['invitationShort'] : $defaults['invitationShort'],
		'invitationLong'  => isset( $strings['invitationLong'] ) ? (string) $strings['invitationLong'] : $defaults['invitationLong'],
		'hashtags'        => isset( $strings['hashtags'] ) ? (string) $strings['hashtags'] : $defaults['hashtags'],
	);

	foreach ( $block->view_script_handles as $handle ) {
		wp_localize_script( $handle, 'vibeCheckShare', $out );
	}
}
add_action( 'init', 'vibe_check_localize_view_share_strings', 20 );

/**
 * Google Fonts for quiz UI (aligned with Tier4.club theme: Big Shoulders Display, Space Mono).
 */
function vibe_check_enqueue_quiz_fonts() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	wp_enqueue_style(
		'vibe-check-fonts',
		'https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@400..900&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap',
		array(),
		VIBE_CHECK_VERSION
	);
}

/**
 * @param string                $content Block HTML.
 * @param array{ blockName?: string } $block Parsed block.
 * @return string
 */
function vibe_check_render_block_enqueue_fonts( $content, $block ) {
	if ( isset( $block['blockName'] ) && 'vibe-check/quiz' === $block['blockName'] ) {
		vibe_check_enqueue_quiz_fonts();
	}
	return $content;
}
add_filter( 'render_block', 'vibe_check_render_block_enqueue_fonts', 5, 2 );

/**
 * Editor canvas: match front-end typography when editing the block.
 */
function vibe_check_enqueue_editor_fonts() {
	$GLOBALS['vibe_check_enable_font_preconnect'] = true;
	vibe_check_enqueue_quiz_fonts();
}
add_action( 'enqueue_block_editor_assets', 'vibe_check_enqueue_editor_fonts' );

/**
 * Mark singular views that include the quiz block so we can emit font preconnect in head.
 */
function vibe_check_flag_font_preconnect_singular() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	// Fast path: avoid parsing all blocks when the post cannot contain this block.
	if ( false === strpos( $post->post_content, 'wp:vibe-check/quiz' ) ) {
		return;
	}
	$GLOBALS['vibe_check_enable_font_preconnect'] = true;
}
add_action( 'wp', 'vibe_check_flag_font_preconnect_singular', 5 );

/**
 * Preconnect to Google Fonts hosts when the quiz block (or block editor) loads those fonts.
 *
 * @param array<int, string|array<string, string>> $urls          URLs to print for relation_type.
 * @param string                                   $relation_type Relation type the filter was invoked for.
 * @return array<int, string|array<string, string>>
 */
function vibe_check_font_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' !== $relation_type || empty( $GLOBALS['vibe_check_enable_font_preconnect'] ) ) {
		return $urls;
	}
	$urls[] = array(
		'href' => 'https://fonts.googleapis.com',
	);
	$urls[] = array(
		'href'        => 'https://fonts.gstatic.com',
		'crossorigin' => 'anonymous',
	);
	return $urls;
}
add_filter( 'wp_resource_hints', 'vibe_check_font_resource_hints', 10, 2 );

/**
 * Tie block script/style `ver` to the plugin version so page/CDN caches cannot serve stale
 * assets when the webpack content hash in *.asset.php stays unchanged across small edits.
 *
 * @param string $src    Script or style URL.
 * @param string $handle Registered handle.
 * @return string
 */
function vibe_check_block_asset_cache_bust( $src, $handle ) {
	if ( strpos( (string) $handle, 'vibe-check' ) === false ) {
		return $src;
	}
	$src = remove_query_arg( 'ver', $src );
	return add_query_arg( 'ver', rawurlencode( VIBE_CHECK_VERSION ), $src );
}
add_filter( 'script_loader_src', 'vibe_check_block_asset_cache_bust', 20, 2 );
add_filter( 'style_loader_src', 'vibe_check_block_asset_cache_bust', 20, 2 );
