<?php
/**
 * Plugin Name:       Vibe Check
 * Plugin URI:        https://github.com/RegionallyFamous/vibe
 * Description:       Personality-style quiz block with shareable result cards and optional OG images.
 * Version:           1.0.10
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Regionally Famous
 * Author URI:        https://github.com/RegionallyFamous/vibe
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

require_once __DIR__ . '/github-updater.php';

/**
 * Back-compat: {@see 'vibe_check_github_updater_collect_slugs'} delegates from the shared library filter.
 */
add_filter(
	'rf_wp_github_release_updater_collect_slugs',
	static function ( $slugs, $checked, $plugin_file ) {
		return apply_filters( 'vibe_check_github_updater_collect_slugs', $slugs, $checked, $plugin_file );
	},
	10,
	3
);

/** GitHub repo for in-plugin updates (also used to clear updater transients). */
define( 'VIBE_CHECK_GITHUB_OWNER', 'RegionallyFamous' );
define( 'VIBE_CHECK_GITHUB_REPO', 'vibe' );

new GitHub_Plugin_Updater(
	__FILE__,
	array(
		'owner' => VIBE_CHECK_GITHUB_OWNER,
		'repo'  => VIBE_CHECK_GITHUB_REPO,
		'token' => defined( 'GITHUB_UPDATER_TOKEN' ) ? GITHUB_UPDATER_TOKEN : '',
	)
);

define( 'VIBE_CHECK_VERSION', '1.0.10' );
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
 * One registry lookup: wp-i18n JSON + localized share strings for the view script.
 */
function vibe_check_init_view_script_i18n_and_share_strings() {
	$registry = WP_Block_Type_Registry::get_instance();
	$block    = $registry->get_registered( 'vibe-check/quiz' );
	if ( ! $block || empty( $block->view_script_handles ) ) {
		return;
	}

	if ( function_exists( 'wp_set_script_translations' ) ) {
		foreach ( $block->view_script_handles as $handle ) {
			wp_set_script_translations(
				$handle,
				'vibe-check',
				VIBE_CHECK_PLUGIN_DIR . 'languages'
			);
		}
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
add_action( 'init', 'vibe_check_init_view_script_i18n_and_share_strings', 20 );

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

/**
 * Clear WordPress core plugin update transient + GitHub release JSON cache (linked from Settings → Vibe Check).
 *
 * @return void
 */
function vibe_check_clear_plugin_update_caches_redirect() {
	if ( ! is_admin() || ! isset( $_GET['vibe_check_clear_plugin_updates'] ) || '1' !== $_GET['vibe_check_clear_plugin_updates'] ) {
		return;
	}
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update plugins.', 'vibe-check' ) );
	}
	check_admin_referer( 'vibe_check_clear_plugin_updates' );
	if ( class_exists( 'GitHub_Plugin_Updater' ) && defined( 'VIBE_CHECK_GITHUB_OWNER' ) && defined( 'VIBE_CHECK_GITHUB_REPO' ) ) {
		delete_site_transient( 'update_plugins' );
		delete_site_transient( GitHub_Plugin_Updater::release_transient_key( VIBE_CHECK_GITHUB_OWNER, VIBE_CHECK_GITHUB_REPO ) );
		GitHub_Plugin_Updater::clear_static_memo();
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                         => 'vibe-check',
				'vibe_check_updates_cleared' => '1',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'vibe_check_clear_plugin_update_caches_redirect', 1 );

/**
 * Confirm cache clear on the settings screen.
 *
 * @return void
 */
function vibe_check_admin_notice_plugin_updates_cleared() {
	if ( ! isset( $_GET['vibe_check_updates_cleared'], $_GET['page'] ) || '1' !== $_GET['vibe_check_updates_cleared'] || 'vibe-check' !== $_GET['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-success is-dismissible"><p>';
	esc_html_e( 'Plugin update caches were cleared. Go to Dashboard → Updates and click “Check again”.', 'vibe-check' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'vibe_check_admin_notice_plugin_updates_cleared' );
