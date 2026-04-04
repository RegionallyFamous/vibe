<?php
/**
 * My Little Pony: character slugs, labels, and QuizResultWriter prompt loader.
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed MLP character slugs (must match editor).
 *
 * @return array<string, string> slug => display label
 */
function vibe_check_mlp_character_choices() {
	return array(
		'twilight-sparkle'   => 'Twilight Sparkle',
		'pinkie-pie'         => 'Pinkie Pie',
		'rarity'             => 'Rarity',
		'applejack'          => 'Applejack',
		'fluttershy'         => 'Fluttershy',
		'rainbow-dash'       => 'Rainbow Dash',
		'spike'              => 'Spike',
		'princess-celestia'  => 'Princess Celestia',
		'princess-luna'      => 'Princess Luna',
		'discord'            => 'Discord',
		'starlight-glimmer'  => 'Starlight Glimmer',
		'trixie'             => 'Trixie',
		'sunset-shimmer'     => 'Sunset Shimmer',
		'derpy'              => 'Derpy (Ditzy Doo)',
		'big-mcintosh'       => 'Big McIntosh',
		'zecora'             => 'Zecora',
		'apple-bloom'        => 'Apple Bloom',
		'sweetie-belle'      => 'Sweetie Belle',
		'scootaloo'          => 'Scootaloo',
		'queen-chrysalis'    => 'Queen Chrysalis',
		'king-sombra'        => 'King Sombra',
		'lord-tirek'         => 'Lord Tirek',
		'cozy-glow'          => 'Cozy Glow',
	);
}

/**
 * Whether slug is a known MLP character.
 *
 * @param string $slug Raw slug.
 * @return bool
 */
function vibe_check_mlp_is_valid_character( $slug ) {
	$slug = sanitize_key( (string) $slug );
	$choices = vibe_check_mlp_character_choices();
	return '' !== $slug && isset( $choices[ $slug ] );
}

/**
 * Display name for slug.
 *
 * @param string $slug Slug.
 * @return string
 */
function vibe_check_mlp_character_label( $slug ) {
	$choices = vibe_check_mlp_character_choices();
	$slug    = sanitize_key( (string) $slug );
	return isset( $choices[ $slug ] ) ? $choices[ $slug ] : '';
}

/**
 * Full QuizResultWriter reference text (bundled markdown).
 *
 * Loads `includes/data/mlp-quiz-writer-prompt.txt` if present, else concatenates
 * `mlp-quiz-writer-prompt-part*.txt` in sorted order.
 *
 * @return string
 */
function vibe_check_mlp_quiz_writer_reference() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}
	$data_dir = VIBE_CHECK_PLUGIN_DIR . 'includes/data/';
	$single    = $data_dir . 'mlp-quiz-writer-prompt.txt';
	if ( is_readable( $single ) ) {
		$raw    = file_get_contents( $single );
		$cached = is_string( $raw ) ? $raw : '';
		$cached = apply_filters( 'vibe_check_mlp_quiz_writer_reference', $cached );
		return $cached;
	}
	$parts = glob( $data_dir . 'mlp-quiz-writer-prompt-part*.txt' );
	if ( ! is_array( $parts ) || array() === $parts ) {
		$cached = apply_filters( 'vibe_check_mlp_quiz_writer_reference', '' );
		return $cached;
	}
	sort( $parts, SORT_STRING );
	$out = '';
	foreach ( $parts as $path ) {
		if ( is_readable( $path ) ) {
			$out .= (string) file_get_contents( $path );
		}
	}
	$cached = apply_filters( 'vibe_check_mlp_quiz_writer_reference', $out );
	return $cached;
}

/**
 * Append MLP QuizResultWriter rules to a base system prompt when preset + character are set.
 *
 * @param string $base_system Base system prompt for this mode.
 * @param string $preset      Style preset slug.
 * @param string $character   MLP character slug.
 * @return string
 */
function vibe_check_claude_append_mlp_quiz_writer_system( $base_system, $preset, $character ) {
	$preset = sanitize_key( (string) $preset );
	if ( 'my-little-pony' !== $preset || ! vibe_check_mlp_is_valid_character( $character ) ) {
		return $base_system;
	}
	$ref = vibe_check_mlp_quiz_writer_reference();
	if ( '' === $ref ) {
		return $base_system;
	}
	$name = vibe_check_mlp_character_label( $character );
	$suffix = "\n\n---\n";
	$suffix .= "## My Little Pony: QuizResultWriter (bundled reference)\n\n";
	$suffix .= sprintf(
		/* translators: %s: character display name */
		__( 'The author selected focus character: **%s**. Use the reference below for voices, dialects, and output rules.', 'vibe-check' ),
		$name
	);
	$suffix .= "\n\n";
	$suffix .= $ref;
	$suffix .= "\n\n### ";
	$suffix .= __( 'JSON quiz output (mandatory)', 'vibe-check' );
	$suffix .= "\n";
	$suffix .= __( '- Reply with ONLY valid JSON as specified in the base rules above.', 'vibe-check' ) . "\n";
	$suffix .= __( '- For each `results[]` row, write `description` as a QuizResultWriter blurb following the profile for the character named or implied by that row\'s `title`.', 'vibe-check' ) . "\n";
	$suffix .= __( '- Each blurb must open with **You\'re [Character Name]!** (or the Trixie / Zecora / Big Mac exceptions from the reference).', 'vibe-check' ) . "\n";
	$suffix .= __( '- Weave the selected focus character into questions, answers, or tone when it fits the topic.', 'vibe-check' ) . "\n";
	return $base_system . $suffix;
}
