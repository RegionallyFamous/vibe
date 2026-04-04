<?php
/**
 * Sanitize quiz payload for JSON-in-attribute (XSS + DoS limits).
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum string lengths (bytes-ish via mbstring when available).
 */
const VIBE_CHECK_MAX_QUIZ_TITLE = 300;
const VIBE_CHECK_MAX_QUIZ_SUBTITLE = 400;
const VIBE_CHECK_MAX_Q_TEXT     = 500;
const VIBE_CHECK_MAX_A_TEXT     = 400;
const VIBE_CHECK_MAX_FEEDBACK   = 200;
const VIBE_CHECK_MAX_R_TITLE    = 200;
const VIBE_CHECK_MAX_R_TAGLINE  = 120;
/** Default max length for result descriptions; override via {@see vibe_check_max_result_description_length()}. */
const VIBE_CHECK_MAX_R_DESC      = 1200;
const VIBE_CHECK_MAX_CTA_LABEL   = 80;
const VIBE_CHECK_MAX_QUESTIONS  = 40;
const VIBE_CHECK_MAX_ANSWERS    = 12;
const VIBE_CHECK_MAX_RESULTS    = 24;

/**
 * Filterable max length for result description (sanitizer).
 *
 * @return int
 */
function vibe_check_max_result_description_length() {
	$max = (int) apply_filters( 'vibe_check_max_result_description_length', VIBE_CHECK_MAX_R_DESC );
	return max( 200, min( 8000, $max ) );
}

/**
 * Bounds for AI-generated quiz shape (Claude prompts + REST validation). Filter: `vibe_check_quiz_generation_config`.
 *
 * Keys: min_results, max_results, min_answers_per_question, max_answers_per_question, min_questions, max_questions.
 *
 * @return array<string, int>
 */
function vibe_check_quiz_generation_config() {
	$defaults = array(
		'min_results'               => 3,
		'max_results'               => 6,
		'min_answers_per_question'  => 3,
		'max_answers_per_question' => 5,
		'min_questions'             => 3,
		'max_questions'             => 5,
	);
	$cfg = apply_filters( 'vibe_check_quiz_generation_config', $defaults );
	if ( ! is_array( $cfg ) ) {
		$cfg = $defaults;
	}
	$out = array();
	foreach ( $defaults as $key => $def ) {
		$out[ $key ] = isset( $cfg[ $key ] ) ? (int) $cfg[ $key ] : $def;
	}
	$out['min_results'] = max( 1, min( VIBE_CHECK_MAX_RESULTS, $out['min_results'] ) );
	$out['max_results'] = max( $out['min_results'], min( VIBE_CHECK_MAX_RESULTS, $out['max_results'] ) );
	$out['min_answers_per_question'] = max( 2, min( VIBE_CHECK_MAX_ANSWERS, $out['min_answers_per_question'] ) );
	$out['max_answers_per_question'] = max( $out['min_answers_per_question'], min( VIBE_CHECK_MAX_ANSWERS, $out['max_answers_per_question'] ) );
	$out['min_questions'] = max( 1, min( VIBE_CHECK_MAX_QUESTIONS, $out['min_questions'] ) );
	$out['max_questions'] = max( $out['min_questions'], min( VIBE_CHECK_MAX_QUESTIONS, $out['max_questions'] ) );
	return $out;
}

/** Max serialized size for REST `existing` body (DoS guard). */
const VIBE_CHECK_MAX_REST_EXISTING_BYTES = 262144;

/** Max assistant JSON text before decode (Claude response / parse guard). */
const VIBE_CHECK_MAX_MODEL_JSON_BYTES = 524288;

/** Max length of JSON passed in `data-quiz` (front-end parse guard; keep in sync with view.js). */
const VIBE_CHECK_MAX_CLIENT_QUIZ_JSON_BYTES = 655360;

/**
 * Whether REST `existing` param is within size limits.
 *
 * @param mixed $raw Raw (array from JSON body or string).
 * @return bool
 */
function vibe_check_rest_existing_within_limit( $raw ) {
	if ( null === $raw || false === $raw ) {
		return true;
	}
	if ( is_string( $raw ) ) {
		return strlen( $raw ) <= VIBE_CHECK_MAX_REST_EXISTING_BYTES;
	}
	if ( is_array( $raw ) ) {
		$enc = wp_json_encode( $raw );
		if ( false === $enc ) {
			return false;
		}
		return strlen( $enc ) <= VIBE_CHECK_MAX_REST_EXISTING_BYTES;
	}
	return false;
}

/**
 * Allowed http(s) URL for result CTAs (empty string if invalid).
 *
 * @param mixed $url Raw.
 * @return string
 */
function vibe_check_sanitize_result_cta_url( $url ) {
	$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		return '';
	}
	return $url;
}

/**
 * Copy CTA fields from base results onto parsed results by matching id (regenerate-results mode).
 *
 * @param array $parsed_results Results from the model.
 * @param array $base_results   Prior sanitized results from the block.
 * @return array
 */
function vibe_check_merge_parsed_results_cta_from_base( array $parsed_results, array $base_results ) {
	$by_id = array();
	foreach ( $base_results as $row ) {
		if ( is_array( $row ) && ! empty( $row['id'] ) ) {
			$by_id[ (string) $row['id'] ] = $row;
		}
	}
	$out = array();
	foreach ( $parsed_results as $pr ) {
		if ( ! is_array( $pr ) || empty( $pr['id'] ) ) {
			$out[] = $pr;
			continue;
		}
		$id = (string) $pr['id'];
		if ( isset( $by_id[ $id ] ) ) {
			$b = $by_id[ $id ];
			foreach ( array( 'ctaUrl', 'ctaLabel', 'redirect', 'imageId', 'tagline' ) as $k ) {
				if ( array_key_exists( $k, $b ) ) {
					$pr[ $k ] = $b[ $k ];
				}
			}
		}
		$out[] = $pr;
	}
	return $out;
}

/**
 * Sanitize block attributes into a safe quiz array for the client.
 *
 * @param array $attributes Raw block attributes.
 * @return array{ title: string, subtitle: string, questions: array, results: array }
 */
function vibe_check_sanitize_quiz_payload( array $attributes ) {
	$title = isset( $attributes['quizTitle'] ) ? sanitize_text_field( (string) $attributes['quizTitle'] ) : '';
	$title = vibe_check_truncate_utf8( $title, VIBE_CHECK_MAX_QUIZ_TITLE );

	$subtitle = isset( $attributes['quizSubtitle'] ) ? sanitize_text_field( (string) $attributes['quizSubtitle'] ) : '';
	$subtitle = vibe_check_truncate_utf8( $subtitle, VIBE_CHECK_MAX_QUIZ_SUBTITLE );

	$results_in = isset( $attributes['results'] ) && is_array( $attributes['results'] ) ? $attributes['results'] : array();
	$results    = array();
	$valid_ids  = array();

	foreach ( array_slice( $results_in, 0, VIBE_CHECK_MAX_RESULTS ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
		if ( '' === $id ) {
			continue;
		}
		$cta_url   = vibe_check_sanitize_result_cta_url( isset( $row['ctaUrl'] ) ? $row['ctaUrl'] : '' );
		$cta_label = isset( $row['ctaLabel'] ) ? vibe_check_truncate_utf8( sanitize_text_field( (string) $row['ctaLabel'] ), VIBE_CHECK_MAX_CTA_LABEL ) : '';
		$redirect  = ( ! empty( $row['redirect'] ) && '' !== $cta_url );

		$image_id = vibe_check_sanitize_result_image_id( isset( $row['imageId'] ) ? $row['imageId'] : 0 );

		$tagline = isset( $row['tagline'] ) ? vibe_check_truncate_utf8( sanitize_text_field( (string) $row['tagline'] ), VIBE_CHECK_MAX_R_TAGLINE ) : '';

		$result_row = array(
			'id'          => $id,
			'title'       => vibe_check_truncate_utf8( isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '', VIBE_CHECK_MAX_R_TITLE ),
			'description' => vibe_check_truncate_utf8( isset( $row['description'] ) ? wp_strip_all_tags( (string) $row['description'] ) : '', vibe_check_max_result_description_length() ),
			'redirect'    => $redirect,
		);
		if ( '' !== $tagline ) {
			$result_row['tagline'] = $tagline;
		}
		if ( $image_id > 0 ) {
			$result_row['imageId'] = $image_id;
		}
		if ( '' !== $cta_url ) {
			$result_row['ctaUrl'] = $cta_url;
			if ( '' !== $cta_label ) {
				$result_row['ctaLabel'] = $cta_label;
			}
		}
		$results[] = $result_row;
		$valid_ids[ $id ] = true;
	}

	$questions_in = isset( $attributes['questions'] ) && is_array( $attributes['questions'] ) ? $attributes['questions'] : array();
	$questions      = array();

	foreach ( array_slice( $questions_in, 0, VIBE_CHECK_MAX_QUESTIONS ) as $q ) {
		if ( ! is_array( $q ) ) {
			continue;
		}
		$qid = isset( $q['id'] ) ? sanitize_key( (string) $q['id'] ) : '';
		if ( '' === $qid ) {
			continue;
		}
		$qtext = isset( $q['text'] ) ? sanitize_text_field( (string) $q['text'] ) : '';
		$qtext = vibe_check_truncate_utf8( $qtext, VIBE_CHECK_MAX_Q_TEXT );

		$answers_in = isset( $q['answers'] ) && is_array( $q['answers'] ) ? $q['answers'] : array();
		$answers    = array();

		foreach ( array_slice( $answers_in, 0, VIBE_CHECK_MAX_ANSWERS ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$aid = isset( $a['id'] ) ? sanitize_key( (string) $a['id'] ) : '';
			if ( '' === $aid ) {
				continue;
			}
			$atext = isset( $a['text'] ) ? sanitize_text_field( (string) $a['text'] ) : '';
			$atext = vibe_check_truncate_utf8( $atext, VIBE_CHECK_MAX_A_TEXT );

			$feedback = '';
			if ( isset( $a['feedback'] ) ) {
				$feedback = vibe_check_truncate_utf8( wp_strip_all_tags( (string) $a['feedback'] ), VIBE_CHECK_MAX_FEEDBACK );
			}

			$scores = array();
			if ( isset( $a['scores'] ) && is_array( $a['scores'] ) ) {
				foreach ( $a['scores'] as $rid => $pts ) {
					$rk = sanitize_key( (string) $rid );
					if ( '' === $rk || ! isset( $valid_ids[ $rk ] ) ) {
						continue;
					}
					$scores[ $rk ] = max( 0, min( 100, (int) $pts ) );
				}
			}

			$answer_row = array(
				'id'     => $aid,
				'text'   => $atext,
				'scores' => $scores,
			);
			if ( '' !== $feedback ) {
				$answer_row['feedback'] = $feedback;
			}
			$answers[] = $answer_row;
		}

		if ( $answers ) {
			$questions[] = array(
				'id'      => $qid,
				'text'    => $qtext,
				'answers' => $answers,
			);
		}
	}

	return array(
		'title'     => $title,
		'subtitle'  => $subtitle,
		'questions' => $questions,
		'results'   => $results,
	);
}

/**
 * Sanitize Media Library attachment id for a result image.
 *
 * @param mixed $raw Raw attribute value.
 * @return int Attachment id or 0.
 */
function vibe_check_sanitize_result_image_id( $raw ) {
	$id = absint( $raw );
	if ( $id <= 0 ) {
		return 0;
	}
	if ( ! wp_attachment_is_image( $id ) ) {
		return 0;
	}
	return $id;
}

/**
 * Add imageUrl and imageAlt to each result for the front-end (from imageId).
 *
 * @param array $quiz Sanitized quiz from vibe_check_sanitize_quiz_payload.
 * @return array
 */
function vibe_check_enrich_quiz_results_media( array $quiz ) {
	if ( empty( $quiz['results'] ) || ! is_array( $quiz['results'] ) ) {
		return $quiz;
	}
	$prime_ids = array();
	foreach ( $quiz['results'] as $row ) {
		if ( is_array( $row ) && ! empty( $row['imageId'] ) ) {
			$pid = absint( $row['imageId'] );
			if ( $pid > 0 ) {
				$prime_ids[] = $pid;
			}
		}
	}
	$prime_ids = array_values( array_unique( $prime_ids ) );
	if ( $prime_ids && function_exists( '_prime_post_caches' ) ) {
		_prime_post_caches( $prime_ids, false );
	}
	foreach ( $quiz['results'] as $i => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$id = isset( $row['imageId'] ) ? absint( $row['imageId'] ) : 0;
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			continue;
		}
		// Full size gives a sharper source for the server-rendered OG / share JPEG.
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( ! is_string( $url ) || '' === $url ) {
			continue;
		}
		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		$alt = is_string( $alt ) ? trim( $alt ) : '';
		if ( '' === $alt && ! empty( $row['title'] ) ) {
			$alt = sanitize_text_field( (string) $row['title'] );
		}
		$quiz['results'][ $i ]['imageUrl'] = esc_url_raw( $url );
		$quiz['results'][ $i ]['imageAlt'] = $alt;
	}
	return $quiz;
}

/**
 * Truncate UTF-8 safely.
 *
 * @param string $str String.
 * @param int    $max Max code units (approx).
 * @return string
 */
function vibe_check_truncate_utf8( $str, $max ) {
	$str = (string) $str;
	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $str, 0, $max, 'UTF-8' );
	}
	return substr( $str, 0, $max );
}
