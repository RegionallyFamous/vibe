<?php
/**
 * Tests for Claude response JSON extraction.
 *
 * @package VibeCheck
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-vibe-check-claude.php';

/**
 * @coversNothing
 */
class ClaudeParseTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_parse_extracts_object_when_prose_before_and_after() {
		$json = '{"quizTitle":"Z","questions":[{"id":"q1","text":"?","answers":[{"id":"a1","text":"x","scores":{"r":1}}]}],"results":[{"id":"r","title":"R","description":"D"}]}';
		$text = "Here is the quiz you asked for.\n\n" . $json . "\n\nLet me know if you need changes.";
		$out  = vibe_check_claude_parse_quiz_json( $text );
		$this->assertIsArray( $out );
		$this->assertSame( 'Z', $out['quizTitle'] );
		$this->assertArrayHasKey( 'questions', $out );
	}

	/**
	 * @return void
	 */
	public function test_parse_second_fence_when_first_is_not_json() {
		$good = '{"quizTitle":"T","questions":[],"results":[]}';
		$text = "```\nnot json\n```\n\n```json\n" . $good . "\n```";
		$out  = vibe_check_claude_parse_quiz_json( $text );
		$this->assertIsArray( $out );
		$this->assertSame( 'T', $out['quizTitle'] );
	}

	/**
	 * @return void
	 */
	public function test_parse_lenient_trailing_comma() {
		$json = '{"quizTitle":"Z","questions":[],"results":[],}';
		$out  = vibe_check_claude_parse_quiz_json( $json );
		$this->assertIsArray( $out );
		$this->assertSame( 'Z', $out['quizTitle'] );
	}
}
