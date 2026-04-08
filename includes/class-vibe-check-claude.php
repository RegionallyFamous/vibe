<?php
/**
 * Anthropic Claude: settings + REST generation (API key never exposed to the browser).
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name for stored API key (encrypted at rest only if you add that layer; stored as saved option).
 */
const VIBE_CHECK_OPTION_CLAUDE_KEY = 'vibe_check_claude_api_key';

/** Option: extra system prompt appended after schema rules (Settings UI + optional). */
const VIBE_CHECK_OPTION_CLAUDE_SYSTEM_ADDENDUM = 'vibe_check_claude_system_addendum';

/** Option: attachment ID for default Open Graph / social preview image (quiz page without ?quiz_result=). */
const VIBE_CHECK_OPTION_DEFAULT_SHARE_IMAGE = 'vibe_check_default_share_image_id';

/**
 * Get Claude API key: wp-config constant wins over DB.
 *
 * @return string
 */
function vibe_check_get_claude_api_key() {
	if ( defined( 'VIBE_CHECK_CLAUDE_API_KEY' ) && VIBE_CHECK_CLAUDE_API_KEY ) {
		return (string) VIBE_CHECK_CLAUDE_API_KEY;
	}
	$key = get_option( VIBE_CHECK_OPTION_CLAUDE_KEY, '' );
	return is_string( $key ) ? $key : '';
}

/**
 * Default Claude model (filterable).
 *
 * @return string
 */
function vibe_check_claude_default_model() {
	return apply_filters( 'vibe_check_claude_model', 'claude-sonnet-4-6' );
}

/**
 * Target max length per result description in Claude prompts (matches fixed-height result card on the front end).
 *
 * Filter: `vibe_check_claude_result_description_soft_max_chars`.
 *
 * @return int
 */
function vibe_check_claude_result_description_soft_max_chars() {
	$n = (int) apply_filters( 'vibe_check_claude_result_description_soft_max_chars', 480 );
	return max( 120, min( 1200, $n ) );
}

/**
 * Build the system prompt that forces JSON-only quiz output (full quiz).
 *
 * @param array|null $cfg Optional; default from vibe_check_quiz_generation_config().
 * @return string
 */
function vibe_check_claude_system_prompt( $cfg = null ) {
	if ( null === $cfg ) {
		$cfg = vibe_check_quiz_generation_config();
	}
	$min_r = (int) $cfg['min_results'];
	$max_r = (int) $cfg['max_results'];
	$min_q = (int) $cfg['min_questions'];
	$max_q = (int) $cfg['max_questions'];
	$min_a = (int) $cfg['min_answers_per_question'];
	$max_a = (int) $cfg['max_answers_per_question'];
	$desc_max = vibe_check_claude_result_description_soft_max_chars();

	return <<<PROMPT
You generate personality-style quiz data for a WordPress block. Reply with ONLY valid JSON — no markdown fences, no commentary.

Schema (all keys required):
{
  "quizTitle": "string",
  "questions": [
    {
      "id": "q1",
      "text": "question text",
      "answers": [
        { "id": "a1", "text": "answer label", "scores": { "result_id": 0 } }
      ]
    }
  ],
  "results": [
    { "id": "result_id", "title": "string", "description": "string" }
  ]
}

Rules:
- Use between {$min_r} and {$max_r} results (inclusive); give each a unique lowercase id (letters, numbers, hyphen only), title, and description. (Result images are chosen in the WordPress editor, not in JSON.) Each "description" MUST be at most {$desc_max} characters (fixed-height result card; longer copy is truncated in the UI). Aim for 2–4 tight sentences, plain prose, no bullet lists.
- Use {$min_q}-{$max_q} questions; every question must have the same number of answer options, between {$min_a} and {$max_a} (inclusive). Each answer has a "scores" object with ALL result ids as keys, values 0-3 integers (higher = more that result).
- Optional: each answer may include "feedback": a very short one-line reaction (max ~80 chars) shown after the user picks that answer; omit if not needed.
- Question and answer ids must be unique across the quiz (e.g. q1, q2, a1, a2…).
- Make the quiz coherent with quizTitle and the user's topic.
PROMPT;
}

/**
 * System prompt: JSON with "questions" array only (partial regenerate).
 *
 * @param array|null $cfg Optional generation bounds.
 * @return string
 */
function vibe_check_claude_system_prompt_questions_only( $cfg = null ) {
	if ( null === $cfg ) {
		$cfg = vibe_check_quiz_generation_config();
	}
	$min_q = (int) $cfg['min_questions'];
	$max_q = (int) $cfg['max_questions'];
	$min_a = (int) $cfg['min_answers_per_question'];
	$max_a = (int) $cfg['max_answers_per_question'];

	return <<<PROMPT
You generate ONLY the "questions" array for a WordPress quiz block. Reply with ONLY valid JSON — no markdown fences, no commentary.

Schema:
{
  "questions": [
    {
      "id": "q1",
      "text": "question text",
      "answers": [
        { "id": "a1", "text": "answer label", "scores": { "result_id": 0 }, "feedback": "optional short line" }
      ]
    }
  ]
}

Rules:
- Use {$min_q}-{$max_q} questions; each question has the same number of answers, between {$min_a} and {$max_a} (inclusive).
- Each answer "scores" must include ALL result ids from the user message context as keys, values 0-3 integers.
- Optional "feedback" on answers: one short line or omit.
- Question and answer ids must be unique (q1, q2, a1, …).
PROMPT;
}

/**
 * System prompt: questions only; author-defined outcomes are sacred (structured authoring).
 *
 * @param array|null $cfg Optional generation bounds.
 * @return string
 */
function vibe_check_claude_system_prompt_from_outcomes( $cfg = null ) {
	if ( null === $cfg ) {
		$cfg = vibe_check_quiz_generation_config();
	}
	$min_q = (int) $cfg['min_questions'];
	$max_q = (int) $cfg['max_questions'];
	$min_a = (int) $cfg['min_answers_per_question'];
	$max_a = (int) $cfg['max_answers_per_question'];
	$desc_max = vibe_check_claude_result_description_soft_max_chars();

	return <<<PROMPT
You generate ONLY the "questions" array (and optionally "quizTitle") for a WordPress quiz block. Reply with ONLY valid JSON — no markdown fences, no commentary.

The author already chose the outcomes (ids, titles, descriptions) in the user message. You MUST NOT output a "results" array. You MUST NOT rename or rewrite outcome titles or descriptions. If an author description is longer than about {$desc_max} characters, leave it as given (the site may trim display); do not expand it.

Schema:
{
  "quizTitle": "optional — only if the user did not set a title and you can suggest a short quiz name",
  "questions": [
    {
      "id": "q1",
      "text": "question text",
      "answers": [
        { "id": "a1", "text": "answer label", "scores": { "result_id": 0 }, "feedback": "optional short line" }
      ]
    }
  ]
}

Rules:
- Use {$min_q}-{$max_q} questions; each question has the same number of answers, between {$min_a} and {$max_a} (inclusive).
- Each answer "scores" must include ALL result ids from the user message as keys, values 0-3 integers.
- Optional "feedback" on answers: one short line or omit.
- Question and answer ids must be unique (q1, q2, a1, …).
- Omit "quizTitle" if the author already provided a quiz title in context.
PROMPT;
}

/**
 * System prompt: JSON with "results" array (and optional quizTitle) only.
 *
 * @param array|null $cfg Optional generation bounds.
 * @return string
 */
function vibe_check_claude_system_prompt_results_only( $cfg = null ) {
	if ( null === $cfg ) {
		$cfg = vibe_check_quiz_generation_config();
	}
	$min_r = (int) $cfg['min_results'];
	$max_r = (int) $cfg['max_results'];
	$desc_max = vibe_check_claude_result_description_soft_max_chars();

	return <<<PROMPT
You generate outcomes for a WordPress quiz block. Reply with ONLY valid JSON — no markdown fences, no commentary.

Schema:
{
  "quizTitle": "optional new title or keep similar",
  "results": [
    { "id": "result_id", "title": "string", "description": "string" }
  ]
}

Rules:
- Return between {$min_r} and {$max_r} results (inclusive). Each "id" MUST be one of the ids listed in the user message (reuse those keys; you may change title and description).
- Each "description" MUST be at most {$desc_max} characters (fixed-height result card; longer copy is truncated in the UI). Aim for 2–4 tight sentences, plain prose, no bullet lists.
- Outcomes must fit the questions context the user provides.
PROMPT;
}

/**
 * Base system prompt for a generation mode (before MLP append).
 *
 * @param string     $mode_key full|from_outcomes|questions|results.
 * @param array|null $cfg      Optional; default from vibe_check_quiz_generation_config().
 * @return string
 */
function vibe_check_claude_base_system_prompt_for_mode( $mode_key, $cfg = null ) {
	if ( null === $cfg ) {
		$cfg = vibe_check_quiz_generation_config();
	}
	$mode_key = sanitize_key( (string) $mode_key );
	switch ( $mode_key ) {
		case 'from_outcomes':
			return vibe_check_claude_system_prompt_from_outcomes( $cfg );
		case 'questions':
			return vibe_check_claude_system_prompt_questions_only( $cfg );
		case 'results':
			return vibe_check_claude_system_prompt_results_only( $cfg );
		case 'full':
		default:
			return vibe_check_claude_system_prompt( $cfg );
	}
}

/**
 * Append Settings addendum + apply filter `vibe_check_claude_system_prompt`.
 *
 * @param string $system        Full system text after MLP append.
 * @param string $mode_key      full|from_outcomes|questions|results.
 * @param string $preset        Style preset slug.
 * @param string $mlp_character MLP character slug.
 * @return string
 */
function vibe_check_claude_finalize_system_prompt( $system, $mode_key, $preset, $mlp_character ) {
	$add = get_option( VIBE_CHECK_OPTION_CLAUDE_SYSTEM_ADDENDUM, '' );
	if ( is_string( $add ) && '' !== trim( $add ) ) {
		$system .= "\n\n---\n" . trim( $add );
	}
	/**
	 * Filter the full Claude system prompt (after JSON rules, MLP reference, and optional settings addendum).
	 *
	 * @param string $system        System prompt.
	 * @param string $preset        Style preset slug.
	 * @param string $mode_key      Generation mode.
	 * @param string $mlp_character MLP character slug (empty if N/A).
	 */
	return (string) apply_filters( 'vibe_check_claude_system_prompt', $system, $preset, $mode_key, $mlp_character );
}

/**
 * System prompt for Claude including optional MLP QuizResultWriter reference.
 *
 * @param string $mode_key      full|from_outcomes|questions|results.
 * @param string $preset        Style preset slug.
 * @param string $mlp_character MLP character slug (my-little-pony only).
 * @return string
 */
function vibe_check_claude_resolve_system_prompt( $mode_key, $preset, $mlp_character ) {
	$base   = vibe_check_claude_base_system_prompt_for_mode( $mode_key );
	$with_mlp = vibe_check_claude_append_mlp_quiz_writer_system( $base, $preset, $mlp_character );
	return vibe_check_claude_finalize_system_prompt( $with_mlp, $mode_key, $preset, $mlp_character );
}

/**
 * Optional style prefix from editor preset (Naruto / Tokidoki / My Little Pony).
 *
 * @param string $preset Preset slug.
 * @return string
 */
function vibe_check_claude_get_preset_prefix( $preset ) {
	$preset = sanitize_key( (string) $preset );
	$map     = array(
		'naruto'         => __( "Style preset: Naruto — ninja adventure tone; honor bonds and rivalry; keep it family-friendly.\n\n", 'vibe-check' ),
		'tokidoki'       => __( "Style preset: Tokidoki — playful kawaii energy, bold and cute; stay upbeat.\n\n", 'vibe-check' ),
		'my-little-pony' => __( "Style preset: My Little Pony — friendship and kindness; colorful, wholesome; keep it family-friendly.\n\n", 'vibe-check' ),
	);
	return isset( $map[ $preset ] ) ? $map[ $preset ] : '';
}

/**
 * Coerce REST "existing" param to block-like attributes array.
 *
 * @param mixed $raw Raw.
 * @return array<string, mixed>
 */
function vibe_check_rest_coerce_existing_quiz( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}
	return array(
		'quizTitle' => isset( $raw['quizTitle'] ) ? (string) $raw['quizTitle'] : '',
		'questions' => isset( $raw['questions'] ) && is_array( $raw['questions'] ) ? $raw['questions'] : array(),
		'results'   => isset( $raw['results'] ) && is_array( $raw['results'] ) ? $raw['results'] : array(),
	);
}

/**
 * Remove UTF-8 BOM if present.
 *
 * @param string $text Raw.
 * @return string
 */
function vibe_check_claude_strip_bom( $text ) {
	$text = (string) $text;
	if ( strlen( $text ) >= 3 && "\xEF\xBB\xBF" === substr( $text, 0, 3 ) ) {
		return substr( $text, 3 );
	}
	return $text;
}

/**
 * Extract the first top-level `{ ... }` substring (string-aware) for JSON embedded in prose.
 *
 * @param string $text Haystack.
 * @return string|null JSON object text or null.
 */
function vibe_check_claude_extract_first_json_object( $text ) {
	$text = (string) $text;
	$start = strpos( $text, '{' );
	if ( false === $start ) {
		return null;
	}
	$len       = strlen( $text );
	$depth     = 0;
	$in_string = false;
	$escape    = false;
	for ( $i = $start; $i < $len; $i++ ) {
		$c = $text[ $i ];
		if ( $in_string ) {
			if ( $escape ) {
				$escape = false;
			} elseif ( '\\' === $c ) {
				$escape = true;
			} elseif ( '"' === $c ) {
				$in_string = false;
			}
			continue;
		}
		if ( '"' === $c ) {
			$in_string = true;
			continue;
		}
		if ( '{' === $c ) {
			$depth++;
		} elseif ( '}' === $c ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $text, $start, $i - $start + 1 );
			}
		}
	}
	return null;
}

/**
 * Try json_decode; on failure optionally strip trailing commas before `}` or `]` (common model mistake).
 *
 * @param string $json JSON text.
 * @return array<string, mixed>|null
 */
function vibe_check_claude_json_decode_lenient( $json ) {
	$json    = (string) $json;
	$depth   = 128;
	$decoded = json_decode( $json, true, $depth );
	if ( is_array( $decoded ) ) {
		return $decoded;
	}
	// Remove trailing commas like `"a": 1, }` or `1, ]`.
	$fixed = preg_replace( '/,\s*([\}\]])/', '$1', $json );
	if ( is_string( $fixed ) && $fixed !== $json ) {
		$decoded = json_decode( $fixed, true, $depth );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}
	return null;
}

/**
 * Extract JSON object from model text (fences, prose before/after, minor JSON fixes).
 *
 * @param string $text Raw assistant text.
 * @return array<string, mixed>|null
 */
function vibe_check_claude_parse_quiz_json( $text ) {
	$text = vibe_check_claude_strip_bom( trim( (string) $text ) );
	if ( strlen( $text ) > VIBE_CHECK_MAX_MODEL_JSON_BYTES ) {
		return null;
	}

	$candidates = array();

	if ( preg_match_all( '/```(?:json)?\s*([\s\S]*?)```/', $text, $fence_matches, PREG_SET_ORDER ) ) {
		foreach ( $fence_matches as $m ) {
			if ( isset( $m[1] ) ) {
				$inner = trim( $m[1] );
				if ( '' !== $inner ) {
					$candidates[] = $inner;
				}
			}
		}
	}

	$candidates[] = $text;

	foreach ( $candidates as $blob ) {
		if ( strlen( $blob ) > VIBE_CHECK_MAX_MODEL_JSON_BYTES ) {
			continue;
		}
		$decoded = vibe_check_claude_json_decode_lenient( $blob );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		$extracted = vibe_check_claude_extract_first_json_object( $blob );
		if ( null !== $extracted && strlen( $extracted ) <= VIBE_CHECK_MAX_MODEL_JSON_BYTES ) {
			$decoded = vibe_check_claude_json_decode_lenient( $extracted );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
	}

	return null;
}

/**
 * Align answer counts across questions in parsed JSON (model output is sometimes inconsistent).
 *
 * Trims extras past max_answers, then pads short questions so every question has the same count
 * (target = max of per-question counts after trim, floored at min_answers and capped at max_answers).
 *
 * @param array<string, mixed> $parsed     Parsed quiz (modified in place).
 * @param string[]             $result_ids Result ids for score keys (any string; sanitized inside).
 * @param array<string, int>   $cfg        Generation config.
 */
function vibe_check_claude_normalize_parsed_questions_answers( array &$parsed, array $result_ids, array $cfg ) {
	if ( empty( $parsed['questions'] ) || ! is_array( $parsed['questions'] ) ) {
		return;
	}
	$ids = array();
	foreach ( $result_ids as $rid ) {
		$k = sanitize_key( (string) $rid );
		if ( '' !== $k ) {
			$ids[] = $k;
		}
	}
	$ids = array_values( array_unique( $ids ) );
	if ( array() === $ids ) {
		return;
	}

	$max_a = (int) $cfg['max_answers_per_question'];
	$min_a = (int) $cfg['min_answers_per_question'];

	foreach ( $parsed['questions'] as $qi => &$q ) {
		if ( ! is_array( $q ) ) {
			continue;
		}
		$answers = isset( $q['answers'] ) && is_array( $q['answers'] ) ? $q['answers'] : array();
		if ( count( $answers ) > $max_a ) {
			$answers = array_slice( $answers, 0, $max_a );
		}
		$q['answers'] = $answers;
	}
	unset( $q );

	$counts = array();
	foreach ( $parsed['questions'] as $q ) {
		if ( is_array( $q ) && isset( $q['answers'] ) && is_array( $q['answers'] ) ) {
			$counts[] = count( $q['answers'] );
		} else {
			$counts[] = 0;
		}
	}
	if ( array() === $counts ) {
		return;
	}
	$target = max( $counts );
	if ( $target < $min_a ) {
		$target = $min_a;
	}
	if ( $target > $max_a ) {
		$target = $max_a;
	}

	$pad_seq = 0;
	foreach ( $parsed['questions'] as $qi => &$q ) {
		if ( ! is_array( $q ) ) {
			continue;
		}
		$answers = isset( $q['answers'] ) && is_array( $q['answers'] ) ? $q['answers'] : array();
		while ( count( $answers ) < $target ) {
			$pad_seq++;
			$aid    = 'vc-pad-' . (int) $qi . '-' . $pad_seq;
			$scores = array();
			foreach ( $ids as $rid ) {
				$scores[ $rid ] = 1;
			}
			$answers[] = array(
				'id'     => $aid,
				'text'   => __( 'Another option', 'vibe-check' ),
				'scores' => $scores,
			);
		}
		if ( count( $answers ) > $target ) {
			$answers = array_slice( $answers, 0, $target );
		}
		$q['answers'] = $answers;
	}
	unset( $q );
}

/**
 * Validate parsed JSON from Claude against generation bounds (before sanitize).
 *
 * @param array<string, mixed> $parsed Parsed array.
 * @param string               $mode   full|from_outcomes|questions|results.
 * @param array<string, mixed> $base   Sanitized existing quiz from the block.
 * @return true|WP_Error
 */
function vibe_check_claude_validate_parsed_quiz( array $parsed, $mode, array $base ) {
	$cfg = vibe_check_quiz_generation_config();
	$mode = sanitize_key( (string) $mode );

	if ( 'full' === $mode ) {
		if ( empty( $parsed['results'] ) || ! is_array( $parsed['results'] ) ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Claude did not return results.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		$rc = count( $parsed['results'] );
		if ( $rc < $cfg['min_results'] || $rc > $cfg['max_results'] ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				sprintf(
					/* translators: 1: minimum results, 2: maximum results */
					__( 'Expected between %1$d and %2$d results in the model output.', 'vibe-check' ),
					(int) $cfg['min_results'],
					(int) $cfg['max_results']
				),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		$result_ids = array();
		foreach ( $parsed['results'] as $r ) {
			if ( is_array( $r ) && ! empty( $r['id'] ) ) {
				$result_ids[] = sanitize_key( (string) $r['id'] );
			}
		}
		if ( count( $result_ids ) !== $rc ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Each result needs a unique id.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		if ( empty( $parsed['questions'] ) || ! is_array( $parsed['questions'] ) ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Claude did not return questions.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		$nq = count( $parsed['questions'] );
		if ( $nq < $cfg['min_questions'] || $nq > $cfg['max_questions'] ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				sprintf(
					/* translators: 1: min questions, 2: max questions */
					__( 'Expected between %1$d and %2$d questions.', 'vibe-check' ),
					(int) $cfg['min_questions'],
					(int) $cfg['max_questions']
				),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		$answer_count = null;
		foreach ( $parsed['questions'] as $q ) {
			if ( ! is_array( $q ) || empty( $q['answers'] ) || ! is_array( $q['answers'] ) ) {
				return new WP_Error(
					'vibe_check_invalid_quiz',
					__( 'Each question needs answers.', 'vibe-check' ),
					array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
				);
			}
			$ac = count( $q['answers'] );
			if ( $ac < $cfg['min_answers_per_question'] || $ac > $cfg['max_answers_per_question'] ) {
				return new WP_Error(
					'vibe_check_invalid_quiz',
					sprintf(
						/* translators: 1: min answers, 2: max answers */
						__( 'Each question must have between %1$d and %2$d answers.', 'vibe-check' ),
						(int) $cfg['min_answers_per_question'],
						(int) $cfg['max_answers_per_question']
					),
					array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
				);
			}
			if ( null === $answer_count ) {
				$answer_count = $ac;
			} elseif ( (int) $answer_count !== $ac ) {
				return new WP_Error(
					'vibe_check_invalid_quiz',
					__( 'All questions must have the same number of answers.', 'vibe-check' ),
					array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
				);
			}
			foreach ( $q['answers'] as $a ) {
				if ( ! is_array( $a ) ) {
					return new WP_Error(
						'vibe_check_invalid_quiz',
						__( 'Invalid answer row in model output.', 'vibe-check' ),
						array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
					);
				}
				$scores = isset( $a['scores'] ) && is_array( $a['scores'] ) ? $a['scores'] : array();
				foreach ( $result_ids as $rid ) {
					if ( ! array_key_exists( $rid, $scores ) ) {
						return new WP_Error(
							'vibe_check_invalid_quiz',
							__( 'Each answer must score every result id.', 'vibe-check' ),
							array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
						);
					}
				}
			}
		}
		return true;
	}

	if ( 'from_outcomes' === $mode || 'questions' === $mode ) {
		$base_ids = array();
		foreach ( $base['results'] as $r ) {
			if ( is_array( $r ) && ! empty( $r['id'] ) ) {
				$base_ids[] = (string) $r['id'];
			}
		}
		if ( empty( $parsed['questions'] ) || ! is_array( $parsed['questions'] ) ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Claude did not return questions.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		$nq = count( $parsed['questions'] );
		if ( $nq < $cfg['min_questions'] || $nq > $cfg['max_questions'] ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				sprintf(
					/* translators: 1: min questions, 2: max questions */
					__( 'Expected between %1$d and %2$d questions.', 'vibe-check' ),
					(int) $cfg['min_questions'],
					(int) $cfg['max_questions']
				),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		$answer_count = null;
		foreach ( $parsed['questions'] as $q ) {
			if ( ! is_array( $q ) || empty( $q['answers'] ) || ! is_array( $q['answers'] ) ) {
				return new WP_Error(
					'vibe_check_invalid_quiz',
					__( 'Each question needs answers.', 'vibe-check' ),
					array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
				);
			}
			$ac = count( $q['answers'] );
			if ( $ac < $cfg['min_answers_per_question'] || $ac > $cfg['max_answers_per_question'] ) {
				return new WP_Error(
					'vibe_check_invalid_quiz',
					sprintf(
						/* translators: 1: min answers, 2: max answers */
						__( 'Each question must have between %1$d and %2$d answers.', 'vibe-check' ),
						(int) $cfg['min_answers_per_question'],
						(int) $cfg['max_answers_per_question']
					),
					array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
				);
			}
			if ( null === $answer_count ) {
				$answer_count = $ac;
			} elseif ( (int) $answer_count !== $ac ) {
				return new WP_Error(
					'vibe_check_invalid_quiz',
					__( 'All questions must have the same number of answers.', 'vibe-check' ),
					array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
				);
			}
			foreach ( $q['answers'] as $a ) {
				if ( ! is_array( $a ) ) {
					return new WP_Error(
						'vibe_check_invalid_quiz',
						__( 'Invalid answer row in model output.', 'vibe-check' ),
						array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
					);
				}
				$scores = isset( $a['scores'] ) && is_array( $a['scores'] ) ? $a['scores'] : array();
				foreach ( $base_ids as $rid ) {
					$rk = sanitize_key( $rid );
					if ( ! array_key_exists( $rk, $scores ) ) {
						return new WP_Error(
							'vibe_check_invalid_quiz',
							__( 'Each answer must score every result id.', 'vibe-check' ),
							array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
						);
					}
				}
			}
		}
		return true;
	}

	if ( 'results' === $mode ) {
		if ( empty( $parsed['results'] ) || ! is_array( $parsed['results'] ) ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Claude did not return results.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		$rc = count( $parsed['results'] );
		if ( $rc < $cfg['min_results'] || $rc > $cfg['max_results'] ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				sprintf(
					/* translators: 1: minimum results, 2: maximum results */
					__( 'Expected between %1$d and %2$d results in the model output.', 'vibe-check' ),
					(int) $cfg['min_results'],
					(int) $cfg['max_results']
				),
				array( 'status' => 422, 'data' => array( 'stage' => 'validate' ) )
			);
		}
		return true;
	}

	return true;
}

/**
 * Whether the current user may generate (editors and up).
 *
 * @return bool
 */
function vibe_check_user_can_generate_quiz() {
	/**
	 * Whether the current user may call POST /vibe-check/v1/generate-quiz.
	 * Default: `current_user_can( 'edit_posts' )` (authors and up).
	 *
	 * @param bool $can Default capability check.
	 */
	return (bool) apply_filters( 'vibe_check_user_can_generate_quiz', current_user_can( 'edit_posts' ) );
}

/**
 * Per-user generation cap (read-only check).
 *
 * Uses transients. On setups where transients are stored only in an external object cache,
 * counters may reset when the cache is flushed; document for multisite/high-availability installs.
 *
 * @param int $user_id User ID.
 * @return bool True if under the cap.
 */
function vibe_check_claude_generate_rate_limit_available( $user_id ) {
	$key = 'vibe_check_gen_rl_' . (int) $user_id;
	$n   = (int) get_transient( $key );
	return $n < 20;
}

/**
 * Record one completed Claude round-trip against the user cap.
 *
 * @param int $user_id User ID.
 */
function vibe_check_claude_generate_rate_limit_bump( $user_id ) {
	$key = 'vibe_check_gen_rl_' . (int) $user_id;
	$n   = (int) get_transient( $key );
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
}

/**
 * Transient cache key for identical system + user messages (short TTL).
 *
 * @param string $model        Model id.
 * @param string $system       System prompt.
 * @param string $user_message User message.
 * @return string
 */
function vibe_check_claude_response_cache_key( $model, $system, $user_message ) {
	return 'vibe_check_cl_c_' . md5( (string) $model . "\n" . $system . "\n" . $user_message );
}

/**
 * WordPress HTTP sets cURL low-speed limits (1024 bytes/sec for 30s). Anthropic often waits
 * before streaming the response body, which triggers cURL error 28. Disable that check for
 * api.anthropic.com only; the overall request timeout still applies.
 *
 * @param resource|\CurlHandle $handle cURL handle.
 * @param array<string, mixed> $request Request args (unused).
 * @param string               $url     Request URL.
 */
function vibe_check_claude_http_api_curl( $handle, $request, $url ) {
	unset( $request );
	if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://api.anthropic.com/' ) ) {
		return;
	}
	if ( defined( 'CURLOPT_LOW_SPEED_LIMIT' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- adjusting WP core defaults for this host.
		curl_setopt( $handle, CURLOPT_LOW_SPEED_LIMIT, 0 );
	}
	if ( defined( 'CURLOPT_LOW_SPEED_TIME' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- adjusting WP core defaults for this host.
		curl_setopt( $handle, CURLOPT_LOW_SPEED_TIME, 0 );
	}
}
add_action( 'http_api_curl', 'vibe_check_claude_http_api_curl', 10, 3 );

/**
 * Call Anthropic Messages API.
 *
 * @param string      $user_message User prompt (topic).
 * @param string|null $system       Override system prompt; default full-quiz schema.
 * @param int         $user_id      User ID for rate limiting (0 = skip). Skipped on cache hit.
 * @return string|WP_Error Assistant text or error.
 */
function vibe_check_claude_api_complete( $user_message, $system = null, $user_id = 0 ) {
	if ( null === $system || '' === $system ) {
		$system = vibe_check_claude_resolve_system_prompt( 'full', '', '' );
	}

	$model = vibe_check_claude_default_model();
	/**
	 * TTL in seconds for caching identical model requests (system + user message). Set to 0 to disable.
	 *
	 * @param int $ttl Default 300.
	 */
	$ttl   = (int) apply_filters( 'vibe_check_claude_response_cache_ttl', 300 );
	$ttl   = max( 0, min( DAY_IN_SECONDS, $ttl ) );
	if ( $ttl > 0 ) {
		$ckey   = vibe_check_claude_response_cache_key( $model, $system, $user_message );
		$cached = get_transient( $ckey );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
	}

	if ( $user_id > 0 && ! vibe_check_claude_generate_rate_limit_available( $user_id ) ) {
		return new WP_Error(
			'rate_limited',
			__( 'Too many generation requests. Try again later.', 'vibe-check' ),
			array(
				'status' => 429,
				'data'   => array( 'retry_after' => HOUR_IN_SECONDS ),
			)
		);
	}

	$api_key = vibe_check_get_claude_api_key();
	if ( '' === $api_key ) {
		return new WP_Error(
			'vibe_check_no_api_key',
			__( 'Add your Anthropic API key under Settings → Vibe Check, or define VIBE_CHECK_CLAUDE_API_KEY in wp-config.php.', 'vibe-check' ),
			array( 'status' => 400 )
		);
	}

	$max_tokens = (int) apply_filters( 'vibe_check_claude_max_tokens', 8192 );
	$max_tokens = max( 256, min( 8192, $max_tokens ) );
	$body       = array(
		'model'      => $model,
		'max_tokens' => $max_tokens,
		'system'     => $system,
		'messages'   => array(
			array(
				'role'    => 'user',
				'content' => __( 'Topic / instructions for the quiz:', 'vibe-check' ) . "\n\n" . $user_message,
			),
		),
	);

	$encoded_body = wp_json_encode( $body );
	if ( false === $encoded_body ) {
		return new WP_Error(
			'vibe_check_encode',
			__( 'Could not encode the API request.', 'vibe-check' ),
			array( 'status' => 400 )
		);
	}
	/**
	 * Max outbound JSON body size in bytes (DoS guard for the Messages API request).
	 *
	 * @param int $max Default 1048576 (1 MiB).
	 */
	$max_req = (int) apply_filters( 'vibe_check_claude_max_request_bytes', 1048576 );
	$max_req = max( 65536, min( 8388608, $max_req ) );
	if ( strlen( $encoded_body ) > $max_req ) {
		return new WP_Error(
			'vibe_check_request_too_large',
			__( 'The generation request is too large. Shorten your prompt or quiz content.', 'vibe-check' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Seconds to wait for the full Anthropic HTTP response (default 120; large prompts need more time).
	 *
	 * @param int $timeout Default 120.
	 */
	$request_timeout = (int) apply_filters( 'vibe_check_claude_request_timeout', 120 );
	$request_timeout = max( 30, min( 600, $request_timeout ) );

	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		array(
			'timeout'             => $request_timeout,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'sslverify'           => true,
			'headers'             => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'                => $encoded_body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );

	if ( strlen( $raw ) > VIBE_CHECK_MAX_MODEL_JSON_BYTES ) {
		return new WP_Error(
			'vibe_check_response_too_large',
			__( 'The API returned a response that was too large to process safely.', 'vibe-check' ),
			array( 'status' => 502 )
		);
	}

	$data = json_decode( $raw, true, 64 );
	if ( JSON_ERROR_DEPTH === json_last_error() ) {
		return new WP_Error(
			'vibe_check_anthropic_parse',
			__( 'API response JSON was too deeply nested.', 'vibe-check' ),
			array( 'status' => 502 )
		);
	}

	if ( $code < 200 || $code >= 300 ) {
		$msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Anthropic API error.', 'vibe-check' );
		return new WP_Error( 'vibe_check_anthropic_http', $msg, array( 'status' => 502 ) );
	}

	if ( ! is_array( $data ) || empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
		return new WP_Error( 'vibe_check_anthropic_parse', __( 'Unexpected API response.', 'vibe-check' ), array( 'status' => 502 ) );
	}

	$text = '';
	foreach ( $data['content'] as $block ) {
		if ( isset( $block['type'] ) && 'text' === $block['type'] && ! empty( $block['text'] ) ) {
			$text .= $block['text'];
		}
	}

	if ( '' === $text ) {
		return new WP_Error( 'vibe_check_empty_response', __( 'Empty response from Claude.', 'vibe-check' ), array( 'status' => 502 ) );
	}

	if ( $ttl > 0 ) {
		$ckey = vibe_check_claude_response_cache_key( $model, $system, $user_message );
		set_transient( $ckey, $text, $ttl );
	}
	if ( $user_id > 0 ) {
		vibe_check_claude_generate_rate_limit_bump( $user_id );
	}

	return $text;
}

/**
 * REST: POST /vibe-check/v1/generate-quiz { "prompt": "..." }
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function vibe_check_rest_generate_quiz( WP_REST_Request $request ) {
	if ( ! vibe_check_user_can_generate_quiz() ) {
		return new WP_Error( 'forbidden', __( 'Sorry, you are not allowed to generate quizzes.', 'vibe-check' ), array( 'status' => 403 ) );
	}

	$uid = get_current_user_id();
	if ( ! $uid ) {
		return new WP_Error( 'forbidden', __( 'Sorry, you are not allowed to generate quizzes.', 'vibe-check' ), array( 'status' => 403 ) );
	}

	$mode = $request->get_param( 'mode' );
	$mode = is_string( $mode ) ? sanitize_key( $mode ) : 'full';
	if ( ! in_array( $mode, array( 'full', 'from_outcomes', 'questions', 'results' ), true ) ) {
		$mode = 'full';
	}

	$preset = $request->get_param( 'preset' );
	$preset = is_string( $preset ) ? sanitize_key( $preset ) : '';
	$prefix = vibe_check_claude_get_preset_prefix( $preset );

	$mlp_character = $request->get_param( 'mlp_character' );
	$mlp_character = is_string( $mlp_character ) ? sanitize_key( $mlp_character ) : '';
	if ( 'my-little-pony' === $preset && ! vibe_check_mlp_is_valid_character( $mlp_character ) ) {
		return new WP_Error(
			'bad_request',
			__( 'Select a pony character when using the My Little Pony style preset.', 'vibe-check' ),
			array( 'status' => 400 )
		);
	}

	$prompt = $request->get_param( 'prompt' );
	$prompt = is_string( $prompt ) ? sanitize_textarea_field( $prompt ) : '';

	$existing_raw = $request->get_param( 'existing' );
	if ( ! vibe_check_rest_existing_within_limit( $existing_raw ) ) {
		return new WP_Error(
			'bad_request',
			__( 'Quiz data sent to the server is too large. Reduce content and try again.', 'vibe-check' ),
			array( 'status' => 400 )
		);
	}
	$existing_arr = vibe_check_rest_coerce_existing_quiz( $existing_raw );
	$base         = vibe_check_sanitize_quiz_payload( $existing_arr );
	$gen_cfg      = vibe_check_quiz_generation_config();

	if ( 'full' === $mode ) {
		if ( strlen( $prompt ) < 10 || strlen( $prompt ) > 4000 ) {
			return new WP_Error( 'bad_request', __( 'Prompt must be between 10 and 4000 characters.', 'vibe-check' ), array( 'status' => 400 ) );
		}
		$user_message = $prefix . $prompt;
		$text         = vibe_check_claude_api_complete(
			$user_message,
			vibe_check_claude_resolve_system_prompt( 'full', $preset, $mlp_character ),
			$uid
		);
	} elseif ( 'from_outcomes' === $mode ) {
		$n_res = count( $base['results'] );
		if ( $n_res < $gen_cfg['min_results'] || $n_res > $gen_cfg['max_results'] ) {
			return new WP_Error(
				'bad_request',
				sprintf(
					/* translators: 1: minimum outcomes, 2: maximum outcomes */
					__( 'Define between %1$d and %2$d outcomes on the block (with unique ids) before generating questions.', 'vibe-check' ),
					(int) $gen_cfg['min_results'],
					(int) $gen_cfg['max_results']
				),
				array( 'status' => 400 )
			);
		}
		foreach ( $base['results'] as $r ) {
			if ( empty( $r['id'] ) || ! is_string( $r['id'] ) ) {
				return new WP_Error(
					'bad_request',
					__( 'Each outcome needs a unique id (letters, numbers, hyphens).', 'vibe-check' ),
					array( 'status' => 400 )
				);
			}
		}
		if ( strlen( $prompt ) < 10 || strlen( $prompt ) > 4000 ) {
			return new WP_Error( 'bad_request', __( 'Describe the quiz in 10–4000 characters (topic, tone, what to ask).', 'vibe-check' ), array( 'status' => 400 ) );
		}
		$ctx = wp_json_encode(
			array(
				'quizTitle' => $base['title'],
				'results'   => $base['results'],
			),
			JSON_UNESCAPED_UNICODE
		);
		if ( false === $ctx ) {
			return new WP_Error( 'bad_request', __( 'Could not encode quiz context.', 'vibe-check' ), array( 'status' => 400 ) );
		}
		$user_message = $prefix . $prompt . "\n\n" . __( 'Author-defined outcomes (do not change; only write questions that score toward these ids):', 'vibe-check' ) . "\n" . $ctx;
		$text         = vibe_check_claude_api_complete(
			$user_message,
			vibe_check_claude_resolve_system_prompt( 'from_outcomes', $preset, $mlp_character ),
			$uid
		);
	} elseif ( 'questions' === $mode ) {
		$n_out = count( $base['results'] );
		if ( $n_out < $gen_cfg['min_results'] || $n_out > $gen_cfg['max_results'] ) {
			return new WP_Error(
				'bad_request',
				sprintf(
					/* translators: 1: minimum outcomes, 2: maximum outcomes */
					__( 'Save between %1$d and %2$d result outcomes before regenerating questions, or generate a full quiz first.', 'vibe-check' ),
					(int) $gen_cfg['min_results'],
					(int) $gen_cfg['max_results']
				),
				array( 'status' => 400 )
			);
		}
		if ( strlen( $prompt ) > 4000 ) {
			return new WP_Error( 'bad_request', __( 'Prompt is too long.', 'vibe-check' ), array( 'status' => 400 ) );
		}
		$instr = '' !== $prompt
			? $prompt
			: __( 'Write fresh questions that match the quiz title and your outcomes.', 'vibe-check' );
		$ctx   = wp_json_encode(
			array(
				'quizTitle' => $base['title'],
				'results'   => $base['results'],
			),
			JSON_UNESCAPED_UNICODE
		);
		if ( false === $ctx ) {
			return new WP_Error( 'bad_request', __( 'Could not encode quiz context.', 'vibe-check' ), array( 'status' => 400 ) );
		}
		$user_message = $prefix . $instr . "\n\n" . __( 'Keep these result definitions exactly (same ids, titles, descriptions):', 'vibe-check' ) . "\n" . $ctx;
		$text         = vibe_check_claude_api_complete(
			$user_message,
			vibe_check_claude_resolve_system_prompt( 'questions', $preset, $mlp_character ),
			$uid
		);
	} else {
		if ( empty( $base['questions'] ) ) {
			return new WP_Error(
				'bad_request',
				__( 'Save at least one question before regenerating results, or generate a full quiz first.', 'vibe-check' ),
				array( 'status' => 400 )
			);
		}
		if ( strlen( $prompt ) > 4000 ) {
			return new WP_Error( 'bad_request', __( 'Prompt is too long.', 'vibe-check' ), array( 'status' => 400 ) );
		}
		$instr = '' !== $prompt
			? $prompt
			: __( 'Write new outcomes that fit these questions (same number of results as defined in the block).', 'vibe-check' );
		$ctx   = wp_json_encode(
			array(
				'quizTitle' => $base['title'],
				'questions' => $base['questions'],
			),
			JSON_UNESCAPED_UNICODE
		);
		if ( false === $ctx ) {
			return new WP_Error( 'bad_request', __( 'Could not encode quiz context.', 'vibe-check' ), array( 'status' => 400 ) );
		}
		$req_ids = array();
		foreach ( $base['results'] as $r ) {
			if ( ! empty( $r['id'] ) ) {
				$req_ids[] = (string) $r['id'];
			}
		}
		$id_note = '' !== implode( '', $req_ids )
			? "\n\n" . sprintf(
				/* translators: %s: comma-separated result ids */
				__( 'Reuse these exact result ids: %s', 'vibe-check' ),
				implode( ', ', $req_ids )
			)
			: '';
		$user_message = $prefix . $instr . "\n\n" . __( 'Current quiz content:', 'vibe-check' ) . "\n" . $ctx . $id_note;
		$text         = vibe_check_claude_api_complete(
			$user_message,
			vibe_check_claude_resolve_system_prompt( 'results', $preset, $mlp_character ),
			$uid
		);
	}

	if ( is_wp_error( $text ) ) {
		return $text;
	}

	$parsed = vibe_check_claude_parse_quiz_json( $text );
	if ( null === $parsed ) {
		return new WP_Error(
			'vibe_check_invalid_json',
			__( 'Could not parse quiz JSON from Claude. Try again with a simpler prompt.', 'vibe-check' ),
			array( 'status' => 502 )
		);
	}

	$result_ids_for_norm = array();
	if ( 'full' === $mode && ! empty( $parsed['results'] ) && is_array( $parsed['results'] ) ) {
		foreach ( $parsed['results'] as $r ) {
			if ( is_array( $r ) && ! empty( $r['id'] ) ) {
				$result_ids_for_norm[] = (string) $r['id'];
			}
		}
	} elseif ( in_array( $mode, array( 'from_outcomes', 'questions' ), true ) && ! empty( $base['results'] ) && is_array( $base['results'] ) ) {
		foreach ( $base['results'] as $r ) {
			if ( is_array( $r ) && ! empty( $r['id'] ) ) {
				$result_ids_for_norm[] = (string) $r['id'];
			}
		}
	}
	if ( array() !== $result_ids_for_norm && ! empty( $parsed['questions'] ) && is_array( $parsed['questions'] ) ) {
		vibe_check_claude_normalize_parsed_questions_answers( $parsed, $result_ids_for_norm, $gen_cfg );
	}

	$struct_ok = vibe_check_claude_validate_parsed_quiz( $parsed, $mode, $base );
	if ( is_wp_error( $struct_ok ) ) {
		return $struct_ok;
	}

	if ( 'from_outcomes' === $mode ) {
		if ( empty( $parsed['questions'] ) || ! is_array( $parsed['questions'] ) ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Claude did not return valid questions. Try again.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'questions' ) )
			);
		}
		$merged_title = isset( $base['title'] ) ? trim( (string) $base['title'] ) : '';
		if ( '' === $merged_title && ! empty( $parsed['quizTitle'] ) ) {
			$merged_title = (string) $parsed['quizTitle'];
		}
		$attrs = array(
			'quizTitle' => $merged_title,
			'questions' => $parsed['questions'],
			'results'   => $base['results'],
		);
	} elseif ( 'questions' === $mode ) {
		if ( empty( $parsed['questions'] ) || ! is_array( $parsed['questions'] ) ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Claude did not return valid questions. Try again.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'questions' ) )
			);
		}
		$attrs = array(
			'quizTitle' => $base['title'],
			'questions' => $parsed['questions'],
			'results'   => $base['results'],
		);
	} elseif ( 'results' === $mode ) {
		if ( empty( $parsed['results'] ) || ! is_array( $parsed['results'] ) ) {
			return new WP_Error(
				'vibe_check_invalid_quiz',
				__( 'Claude did not return valid results. Try again.', 'vibe-check' ),
				array( 'status' => 422, 'data' => array( 'stage' => 'results' ) )
			);
		}
		$new_title = isset( $parsed['quizTitle'] ) ? (string) $parsed['quizTitle'] : $base['title'];
		$merged    = vibe_check_merge_parsed_results_cta_from_base( $parsed['results'], $base['results'] );
		$attrs     = array(
			'quizTitle' => $new_title,
			'questions' => $base['questions'],
			'results'   => $merged,
		);
	} else {
		$attrs = array(
			'quizTitle' => isset( $parsed['quizTitle'] ) ? $parsed['quizTitle'] : '',
			'questions' => isset( $parsed['questions'] ) ? $parsed['questions'] : array(),
			'results'   => isset( $parsed['results'] ) ? $parsed['results'] : array(),
		);
	}

	$sanitized = vibe_check_sanitize_quiz_payload( $attrs );

	if ( empty( $sanitized['questions'] ) || empty( $sanitized['results'] ) ) {
		return new WP_Error(
			'vibe_check_invalid_quiz',
			__( 'Generated quiz failed validation. Check that scores reference each result id.', 'vibe-check' ),
			array( 'status' => 422, 'data' => array( 'stage' => 'sanitize' ) )
		);
	}

	return rest_ensure_response(
		array(
			'quizTitle' => $sanitized['title'],
			'questions' => $sanitized['questions'],
			'results'   => $sanitized['results'],
		)
	);
}

/**
 * Register REST route.
 */
function vibe_check_register_claude_routes() {
	register_rest_route(
		'vibe-check/v1',
		'/generate-quiz',
		array(
			'methods'             => 'POST',
			'callback'            => 'vibe_check_rest_generate_quiz',
			'permission_callback' => static function () {
				return vibe_check_user_can_generate_quiz();
			},
			'args'                => array(
				'prompt'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'mode'     => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => 'full',
					'sanitize_callback' => 'sanitize_key',
				),
				'preset'   => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'mlp_character' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'existing' => array(
					'required' => false,
					'type'     => 'object',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'vibe_check_register_claude_routes' );

/**
 * Settings: register option.
 */
function vibe_check_register_claude_settings() {
	register_setting(
		'vibe_check_settings',
		VIBE_CHECK_OPTION_CLAUDE_KEY,
		array(
			'type'              => 'string',
			'sanitize_callback' => static function ( $value ) {
				if ( ! is_string( $value ) ) {
					$value = '';
				}
				$value = trim( sanitize_text_field( $value ) );
				if ( strlen( $value ) > 512 ) {
					$value = substr( $value, 0, 512 );
				}
				if ( '' === $value ) {
					return (string) get_option( VIBE_CHECK_OPTION_CLAUDE_KEY, '' );
				}
				return $value;
			},
			'default'           => '',
		)
	);
	register_setting(
		'vibe_check_settings',
		VIBE_CHECK_OPTION_CLAUDE_SYSTEM_ADDENDUM,
		array(
			'type'              => 'string',
			'sanitize_callback' => static function ( $value ) {
				if ( ! is_string( $value ) ) {
					return '';
				}
				$value = sanitize_textarea_field( $value );
				return function_exists( 'vibe_check_truncate_utf8' )
					? vibe_check_truncate_utf8( $value, 8000 )
					: substr( $value, 0, 8000 );
			},
			'default'           => '',
		)
	);
	register_setting(
		'vibe_check_settings',
		VIBE_CHECK_OPTION_DEFAULT_SHARE_IMAGE,
		array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ) {
				$id = absint( $value );
				if ( $id > 0 && function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $id ) ) {
					return 0;
				}
				return $id;
			},
			'default'           => 0,
		)
	);
}
add_action( 'admin_init', 'vibe_check_register_claude_settings' );

/**
 * Settings page HTML.
 */
function vibe_check_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$has_constant = defined( 'VIBE_CHECK_CLAUDE_API_KEY' ) && VIBE_CHECK_CLAUDE_API_KEY;
	$stored       = get_option( VIBE_CHECK_OPTION_CLAUDE_KEY, '' );
	$masked       = $stored ? true : false;
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p>
			<?php
			esc_html_e( 'Enter your Anthropic API key to generate quizzes with Claude from the block editor. The key is only used on the server and is never sent to visitors.', 'vibe-check' );
			?>
		</p>
		<div class="notice notice-warning">
			<p>
				<?php
				esc_html_e( 'The API key is stored in the WordPress database as plain text unless you define VIBE_CHECK_CLAUDE_API_KEY in wp-config.php (recommended for production). Anyone with database or backup access could read a saved key.', 'vibe-check' );
				?>
			</p>
		</div>
		<?php if ( $has_constant ) : ?>
			<div class="notice notice-info">
				<p>
					<?php
					esc_html_e( 'VIBE_CHECK_CLAUDE_API_KEY is set in wp-config.php — the field below is ignored.', 'vibe-check' );
					?>
				</p>
			</div>
		<?php endif; ?>
		<form action="options.php" method="post">
			<?php settings_fields( 'vibe_check_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="vibe_check_claude_api_key"><?php esc_html_e( 'Anthropic API key', 'vibe-check' ); ?></label>
					</th>
					<td>
						<input
							name="<?php echo esc_attr( VIBE_CHECK_OPTION_CLAUDE_KEY ); ?>"
							id="vibe_check_claude_api_key"
							type="password"
							class="regular-text code"
							value=""
							autocomplete="off"
							placeholder="<?php echo $masked ? esc_attr( '•••••••••••••••' ) : ''; ?>"
							<?php disabled( $has_constant ); ?>
						/>
						<p class="description">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %s: Anthropic console URL */
									__( 'Create a key at <a href="%s" target="_blank" rel="noopener noreferrer">Anthropic Console</a>. For production, you can instead define <code>VIBE_CHECK_CLAUDE_API_KEY</code> in <code>wp-config.php</code> (never commit keys to git).', 'vibe-check' ),
									'https://console.anthropic.com/'
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="vibe_check_claude_system_addendum"><?php esc_html_e( 'System prompt addendum', 'vibe-check' ); ?></label>
					</th>
					<td>
						<?php
						$addendum = get_option( VIBE_CHECK_OPTION_CLAUDE_SYSTEM_ADDENDUM, '' );
						$addendum = is_string( $addendum ) ? $addendum : '';
						?>
						<textarea
							name="<?php echo esc_attr( VIBE_CHECK_OPTION_CLAUDE_SYSTEM_ADDENDUM ); ?>"
							id="vibe_check_claude_system_addendum"
							class="large-text code"
							rows="6"
						><?php echo esc_textarea( $addendum ); ?></textarea>
						<p class="description">
							<?php
							esc_html_e( 'Optional text appended after the JSON schema rules (and after any style / MLP reference). Use for character voice, tone, or fandom constraints. Developers can also use the vibe_check_claude_system_prompt filter.', 'vibe-check' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="vibe_check_default_share_image_id"><?php esc_html_e( 'Default share image', 'vibe-check' ); ?></label>
					</th>
					<td>
						<?php
						$share_img_id = (int) get_option( VIBE_CHECK_OPTION_DEFAULT_SHARE_IMAGE, 0 );
						$share_img_id = $share_img_id > 0 && wp_attachment_is_image( $share_img_id ) ? $share_img_id : 0;
						$share_img_url = $share_img_id ? (string) wp_get_attachment_image_url( $share_img_id, 'medium' ) : '';
						?>
						<input
							type="hidden"
							name="<?php echo esc_attr( VIBE_CHECK_OPTION_DEFAULT_SHARE_IMAGE ); ?>"
							id="vibe_check_default_share_image_id"
							value="<?php echo esc_attr( (string) $share_img_id ); ?>"
						/>
						<p class="vibe-check-share-image-preview-wrap" style="margin:0 0 8px;">
							<img
								id="vibe-check-share-image-preview"
								src="<?php echo $share_img_url ? esc_url( $share_img_url ) : ''; ?>"
								alt=""
								style="max-width:320px;height:auto;border:1px solid #c3c4c7;border-radius:4px;<?php echo $share_img_url ? '' : 'display:none;'; ?>"
							/>
						</p>
						<p>
							<button type="button" class="button" id="vibe-check-select-share-image">
								<?php esc_html_e( 'Select image', 'vibe-check' ); ?>
							</button>
							<button type="button" class="button" id="vibe-check-remove-share-image" style="<?php echo $share_img_id ? '' : 'display:none;'; ?>">
								<?php esc_html_e( 'Remove', 'vibe-check' ); ?>
							</button>
						</p>
						<p class="description">
							<?php
							esc_html_e( 'Used as og:image when someone shares the quiz page URL before taking the quiz (no ?quiz_result= in the link). Result links still use the generated result card image.', 'vibe-check' );
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'vibe-check' ) ); ?>
		</form>
		<?php if ( current_user_can( 'update_plugins' ) && defined( 'VIBE_CHECK_PLUGIN_BASENAME' ) ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Plugin updates (GitHub)', 'vibe-check' ); ?></h2>
			<p class="description">
				<?php
				esc_html_e( 'If Dashboard → Updates does not show a new Vibe Check release, clear caches here, then open Updates and click “Check again”. WordPress also caches checks for several hours.', 'vibe-check' );
				?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Installed plugin file:', 'vibe-check' ); ?></strong>
				<code><?php echo esc_html( VIBE_CHECK_PLUGIN_BASENAME ); ?></code>
			</p>
			<p class="description">
				<?php
				esc_html_e( 'Updates attach to this exact path. The plugin folder should be vibe-check with main file vibe-check.php (standard zip layout).', 'vibe-check' );
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'vibe-check', 'vibe_check_clear_plugin_updates' => '1' ), admin_url( 'options-general.php' ) ), 'vibe_check_clear_plugin_updates' ) ); ?>">
					<?php esc_html_e( 'Clear update caches', 'vibe-check' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Register admin menu.
 */
function vibe_check_add_settings_page() {
	add_options_page(
		__( 'Vibe Check', 'vibe-check' ),
		__( 'Vibe Check', 'vibe-check' ),
		'manage_options',
		'vibe-check',
		'vibe_check_render_settings_page'
	);
}
add_action( 'admin_menu', 'vibe_check_add_settings_page' );

/**
 * Media picker for default share image on settings screen.
 *
 * @param string $hook_suffix Current admin page.
 */
function vibe_check_admin_enqueue_settings_assets( $hook_suffix ) {
	if ( 'settings_page_vibe-check' !== $hook_suffix ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script( 'jquery' );
	$script = <<<'JS'
jQuery(function ($) {
	var frame;
	$("#vibe-check-select-share-image").on("click", function (e) {
		e.preventDefault();
		if (frame) {
			frame.open();
			return;
		}
		frame = wp.media({
			title: vibeCheckSettingsL10n.selectTitle,
			button: { text: vibeCheckSettingsL10n.useImage },
			library: { type: "image" },
			multiple: false
		});
		frame.on("select", function () {
			var att = frame.state().get("selection").first().toJSON();
			$("#vibe_check_default_share_image_id").val(att.id);
			$("#vibe-check-share-image-preview").attr("src", att.url).show();
			$("#vibe-check-remove-share-image").show();
		});
		frame.open();
	});
	$("#vibe-check-remove-share-image").on("click", function (e) {
		e.preventDefault();
		$("#vibe_check_default_share_image_id").val("0");
		$("#vibe-check-share-image-preview").attr("src", "").hide();
		$(this).hide();
	});
});
JS;
	wp_add_inline_script(
		'jquery',
		$script,
		'after'
	);
	wp_localize_script(
		'jquery',
		'vibeCheckSettingsL10n',
		array(
			'selectTitle' => __( 'Choose default share image', 'vibe-check' ),
			'useImage'    => __( 'Use this image', 'vibe-check' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'vibe_check_admin_enqueue_settings_assets' );
