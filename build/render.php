<?php
/**
 * Vibe Check — server render.
 *
 * @package VibeCheck
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$quiz = vibe_check_sanitize_quiz_payload( is_array( $attributes ) ? $attributes : array() );
$quiz = vibe_check_enrich_quiz_results_media( $quiz );

if ( empty( $quiz['results'] ) || empty( $quiz['questions'] ) ) {
	return;
}

$post_id = get_the_ID() ? (int) get_the_ID() : 0;

$quiz_json = wp_json_encode(
	$quiz,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
if ( false === $quiz_json ) {
	return;
}
if ( strlen( $quiz_json ) > VIBE_CHECK_MAX_CLIENT_QUIZ_JSON_BYTES ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'vibe-check-quiz',
	)
);

$default_share_image_url = '';
if ( function_exists( 'vibe_check_get_default_share_image_url' ) ) {
	$default_share_image_url = vibe_check_get_default_share_image_url();
}
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-quiz="<?php echo esc_attr( $quiz_json ); ?>" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>" data-vibe-check-og-image-endpoint="<?php echo esc_url( rest_url( 'vibe-check/v1/og-image' ) ); ?>"<?php echo is_string( $default_share_image_url ) && '' !== $default_share_image_url ? ' data-vibe-check-default-share-image="' . esc_url( $default_share_image_url ) . '"' : ''; ?>>
	<div class="vibe-check-inner" hidden>
		<div class="vibe-check-intro">
			<div class="vibe-check-intro-card">
				<p class="vibe-check-intro-badge"><?php esc_html_e( 'Personality Quiz', 'vibe-check' ); ?></p>
				<h2 class="vibe-check-intro-title"></h2>
				<p class="vibe-check-intro-subtitle"><?php echo esc_html( '' !== ( $quiz['subtitle'] ?? '' ) ? $quiz['subtitle'] : __( 'Answer honestly — your vibe will find its match.', 'vibe-check' ) ); ?></p>
				<div class="vibe-check-last-result-group" hidden>
					<span class="vibe-check-last-result-stamp" aria-hidden="true"><?php esc_html_e( 'Welcome back', 'vibe-check' ); ?></span>
					<p class="vibe-check-last-result" role="status" aria-live="polite" aria-atomic="true" hidden></p>
				</div>
				<div class="vibe-check-intro-meta" aria-label="<?php esc_attr_e( 'Quiz details', 'vibe-check' ); ?>"></div>
				<button type="button" class="vibe-check-start"><?php esc_html_e( 'Let’s go', 'vibe-check' ); ?></button>
				<p class="vibe-check-intro-footnote"><?php esc_html_e( 'No sign-up. No data collected. Just vibes.', 'vibe-check' ); ?></p>
			</div>
		</div>
		<div class="vibe-check-body" hidden></div>
		<div class="vibe-check-calculating" hidden>
			<div class="vibe-check-calculating-card">
				<div class="vibe-check-calculating-dots" aria-hidden="true">
					<span class="vibe-check-calculating-dot"></span>
					<span class="vibe-check-calculating-dot"></span>
					<span class="vibe-check-calculating-dot"></span>
				</div>
				<p class="vibe-check-calculating-title"><?php esc_html_e( 'Calculating your vibe', 'vibe-check' ); ?></p>
				<div class="vibe-check-calculating-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="vibe-check-calculating-progress"></div>
				</div>
			</div>
		</div>
		<div class="vibe-check-result" hidden></div>
	</div>
	<p class="vibe-check-announcer screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></p>
	<noscript>
		<p><?php esc_html_e( 'Enable JavaScript to take this quiz.', 'vibe-check' ); ?></p>
	</noscript>
</div>
