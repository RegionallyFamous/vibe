<?php
/**
 * Tests for quiz sanitization and CTA merge helpers.
 *
 * @package VibeCheck
 */

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class QuizDataTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_sanitize_strips_unknown_score_keys() {
		$out = vibe_check_sanitize_quiz_payload(
			array(
				'quizTitle' => 'Test Quiz',
				'results'   => array(
					array(
						'id'          => 'a',
						'title'       => 'A',
						'description' => 'Desc A',
					),
				),
				'questions' => array(
					array(
						'id'      => 'q1',
						'text'    => 'Q1?',
						'answers' => array(
							array(
								'id'     => 'an1',
								'text'   => 'Yes',
								'scores' => array(
									'a' => 2,
									'x' => 99,
								),
							),
						),
					),
				),
			)
		);
		$this->assertSame( 'Test Quiz', $out['title'] );
		$this->assertSame( '', $out['subtitle'] );
		$this->assertArrayHasKey( 'a', $out['questions'][0]['answers'][0]['scores'] );
		$this->assertArrayNotHasKey( 'x', $out['questions'][0]['answers'][0]['scores'] );
	}

	/**
	 * @return void
	 */
	public function test_sanitize_cta_http_only() {
		$out = vibe_check_sanitize_quiz_payload(
			array(
				'quizTitle' => 'T',
				'results'   => array(
					array(
						'id'          => 'r1',
						'title'       => 'R',
						'description' => 'D',
						'ctaUrl'      => 'javascript:alert(1)',
						'ctaLabel'    => 'Bad',
						'redirect'    => true,
					),
				),
				'questions' => array(),
			)
		);
		$this->assertSame( array(), $out['questions'] );
		$this->assertFalse( $out['results'][0]['redirect'] );
		$this->assertArrayNotHasKey( 'ctaUrl', $out['results'][0] );
	}

	/**
	 * @return void
	 */
	public function test_sanitize_preserves_quiz_subtitle_and_result_tagline() {
		$out = vibe_check_sanitize_quiz_payload(
			array(
				'quizTitle'    => 'T',
				'quizSubtitle' => 'Under the title line.',
				'results'      => array(
					array(
						'id'          => 'r1',
						'title'       => 'R',
						'description' => 'D',
						'tagline'     => 'Short chip',
					),
				),
				'questions'    => array(
					array(
						'id'      => 'q1',
						'text'    => 'Q?',
						'answers' => array(
							array(
								'id'     => 'a1',
								'text'   => 'Ok',
								'scores' => array( 'r1' => 1 ),
							),
						),
					),
				),
			)
		);
		$this->assertSame( 'Under the title line.', $out['subtitle'] );
		$this->assertSame( 'Short chip', $out['results'][0]['tagline'] );
	}

	/**
	 * @return void
	 */
	public function test_sanitize_keeps_https_cta_and_redirect() {
		$out = vibe_check_sanitize_quiz_payload(
			array(
				'quizTitle' => 'T',
				'results'   => array(
					array(
						'id'          => 'r1',
						'title'       => 'R',
						'description' => 'D',
						'ctaUrl'      => 'https://example.com/page',
						'ctaLabel'    => 'Go',
						'redirect'    => true,
					),
				),
				'questions' => array(),
			)
		);
		$this->assertTrue( $out['results'][0]['redirect'] );
		$this->assertSame( 'https://example.com/page', $out['results'][0]['ctaUrl'] );
		$this->assertSame( 'Go', $out['results'][0]['ctaLabel'] );
	}

	/**
	 * @return void
	 */
	public function test_merge_parsed_results_cta_from_base() {
		$parsed = array(
			array(
				'id'          => 'a',
				'title'       => 'New A',
				'description' => 'New',
			),
		);
		$base   = array(
			array(
				'id'          => 'a',
				'title'       => 'Old A',
				'description' => 'Old',
				'ctaUrl'      => 'https://example.com',
				'ctaLabel'    => 'Shop',
				'redirect'    => false,
				'imageId'     => 42,
			),
		);
		$merged = vibe_check_merge_parsed_results_cta_from_base( $parsed, $base );
		$this->assertSame( 'https://example.com', $merged[0]['ctaUrl'] );
		$this->assertSame( 'Shop', $merged[0]['ctaLabel'] );
		$this->assertFalse( $merged[0]['redirect'] );
		$this->assertSame( 42, $merged[0]['imageId'] );
		$this->assertSame( 'New A', $merged[0]['title'] );
	}

	/**
	 * @return void
	 */
	public function test_sanitize_strips_invalid_image_id() {
		$out = vibe_check_sanitize_quiz_payload(
			array(
				'quizTitle' => 'T',
				'results'   => array(
					array(
						'id'          => 'r1',
						'title'       => 'R',
						'description' => 'D',
						'imageId'     => 7,
					),
				),
				'questions' => array(),
			)
		);
		$this->assertArrayNotHasKey( 'imageId', $out['results'][0] );
	}

	/**
	 * @return void
	 */
	public function test_sanitize_keeps_valid_image_id() {
		$out = vibe_check_sanitize_quiz_payload(
			array(
				'quizTitle' => 'T',
				'results'   => array(
					array(
						'id'          => 'r1',
						'title'       => 'R',
						'description' => 'D',
						'imageId'     => 42,
					),
				),
				'questions' => array(),
			)
		);
		$this->assertSame( 42, $out['results'][0]['imageId'] );
	}

	/**
	 * @return void
	 */
	public function test_enrich_adds_image_url_and_alt() {
		$quiz = array(
			'title'     => 'T',
			'questions' => array(),
			'results'   => array(
				array(
					'id'          => 'a',
					'title'       => 'My title',
					'description' => 'D',
					'imageId'     => 42,
				),
			),
		);
		$out  = vibe_check_enrich_quiz_results_media( $quiz );
		$this->assertSame(
			'https://example.com/wp-content/uploads/test-42.jpg',
			$out['results'][0]['imageUrl']
		);
		$this->assertSame( 'Alt from meta', $out['results'][0]['imageAlt'] );
	}

	/**
	 * @return void
	 */
	public function test_rest_existing_within_limit_accepts_small_array() {
		$this->assertTrue(
			vibe_check_rest_existing_within_limit(
				array( 'quizTitle' => 'Hi', 'results' => array(), 'questions' => array() )
			)
		);
	}
}
