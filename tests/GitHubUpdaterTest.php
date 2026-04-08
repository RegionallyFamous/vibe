<?php
/**
 * Tests for GitHub_Plugin_Updater (mocked HTTP; no network by default).
 *
 * @package VibeCheck
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers GitHub_Plugin_Updater
 */
class GitHubUpdaterTest extends TestCase {

	/**
	 * Fake main file path → plugin_basename vibe-check/vibe-check.php
	 */
	private const FAKE_PLUGIN_FILE = '/var/www/wp-content/plugins/vibe-check/vibe-check.php';

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['vibe_test_wp_remote_get_handler'] );
		$GLOBALS['vibe_test_transients'] = array();
		vibe_test_github_updater_reset_static_memo();
		parent::tearDown();
	}

	/**
	 * @return GitHub_Plugin_Updater
	 */
	private function make_updater( $owner = 'RegionallyFamous', $repo = 'vibe' ) {
		return new GitHub_Plugin_Updater(
			self::FAKE_PLUGIN_FILE,
			array(
				'owner' => $owner,
				'repo'  => $repo,
				'token' => '',
			)
		);
	}

	/**
	 * @return ReflectionClass
	 */
	private function updater_class() {
		return new ReflectionClass( 'GitHub_Plugin_Updater' );
	}

	/**
	 * @return void
	 */
	public function test_sanitize_github_owner_preserves_mixed_case_after_lower() {
		$m = $this->updater_class()->getMethod( 'sanitize_github_owner' );
		$this->assertSame( 'regionallyfamous', $m->invoke( null, 'RegionallyFamous' ) );
		// Underscores are stripped (GitHub usernames do not use them).
		$this->assertSame( 'foobar', $m->invoke( null, 'Foo_Bar' ) );
	}

	/**
	 * @return void
	 */
	public function test_sanitize_github_repo_lowercases() {
		$m = $this->updater_class()->getMethod( 'sanitize_github_repo' );
		$this->assertSame( 'vibe', $m->invoke( null, 'Vibe' ) );
	}

	/**
	 * @return void
	 */
	public function test_parse_version_strips_v_prefix() {
		$u = $this->make_updater();
		$m = $this->updater_class()->getMethod( 'parse_version' );
		$this->assertSame( '1.0.4', $m->invoke( $u, 'v1.0.4' ) );
		$this->assertSame( '1.0.4', $m->invoke( $u, 'V1.0.4' ) );
	}

	/**
	 * @return void
	 */
	public function test_get_latest_release_requests_encoded_owner_in_url() {
		$captured = '';
		$GLOBALS['vibe_test_wp_remote_get_handler'] = static function ( $url ) use ( &$captured ) {
			$captured = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v9.9.9',
						'assets'   => array(
							array(
								'name'                   => 'vibe-check.zip',
								'browser_download_url'   => 'https://github.com/RegionallyFamous/vibe/releases/download/v9.9.9/vibe-check.zip',
							),
						),
					)
				),
			);
		};

		$ref = $this->updater_class();
		$u   = $this->make_updater( 'RegionallyFamous', 'vibe' );
		$gm  = $ref->getMethod( 'get_latest_release' );
		$out = $gm->invoke( $u );

		$this->assertStringContainsString( 'regionallyfamous', $captured );
		$this->assertStringNotContainsString( 'Regionally', $captured );
		$this->assertSame( 'v9.9.9', $out['tag_name'] ?? '' );
	}

	/**
	 * @return void
	 */
	public function test_inject_update_adds_package_when_remote_is_newer() {
		$GLOBALS['vibe_test_wp_remote_get_handler'] = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v2.0.0',
						'assets'   => array(
							array(
								'name'                 => 'vibe-check.zip',
								'browser_download_url' => 'https://github.com/o/r/releases/download/v2/vibe-check.zip',
							),
						),
					)
				),
			);
		};

		$updater = $this->make_updater();
		$t       = new stdClass();
		$t->checked = array( 'vibe-check/vibe-check.php' => '1.0.0' );
		$t->response = array();

		$out = $updater->inject_update( $t );

		$this->assertArrayHasKey( 'vibe-check/vibe-check.php', $out->response );
		$row = $out->response['vibe-check/vibe-check.php'];
		$this->assertSame( '2.0.0', $row->new_version );
		$this->assertSame( 'vibe-check', $row->slug, 'WordPress core expects directory slug, not basename.' );
		$this->assertSame( 'vibe-check/vibe-check.php', $row->plugin );
		$this->assertStringStartsWith( 'https://', $row->package );
	}

	/**
	 * @return void
	 */
	public function test_inject_update_skips_when_installed_is_same_or_newer() {
		$GLOBALS['vibe_test_wp_remote_get_handler'] = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v1.0.4',
						'assets'   => array(
							array(
								'name'                 => 'vibe-check.zip',
								'browser_download_url' => 'https://github.com/o/r/releases/download/v1.0.4/vibe-check.zip',
							),
						),
					)
				),
			);
		};

		$updater = $this->make_updater();
		$t       = new stdClass();
		$t->checked  = array( 'vibe-check/vibe-check.php' => '1.0.4' );
		$t->response = array();

		$out = $updater->inject_update( $t );
		$this->assertSame( array(), $out->response );

		$t->checked = array( 'vibe-check/vibe-check.php' => '1.0.7' );
		$t->response = array();
		$out2        = $updater->inject_update( $t );
		$this->assertSame( array(), $out2->response );
	}

	/**
	 * @return void
	 */
	public function test_inject_update_skips_when_no_trusted_zip() {
		$GLOBALS['vibe_test_wp_remote_get_handler'] = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name' => 'v9.0.0',
						'assets'   => array(
							array(
								'name'                 => 'evil.zip',
								'browser_download_url' => 'https://evil.example.com/payload.zip',
							),
						),
					)
				),
			);
		};

		$updater = $this->make_updater();
		$t       = new stdClass();
		$t->checked  = array( 'vibe-check/vibe-check.php' => '1.0.0' );
		$t->response = array();

		$out = $updater->inject_update( $t );
		$this->assertSame( array(), $out->response );
	}

	/**
	 * @return void
	 */
	public function test_release_transient_key_matches_sanitized_owner_repo() {
		$key = GitHub_Plugin_Updater::release_transient_key( 'RegionallyFamous', 'vibe' );
		$this->assertSame( 'ghu_' . md5( 'regionallyfamous|vibe' ), $key );
	}

	/**
	 * @return void
	 */
	public function test_inject_update_empty_checked_noop() {
		$updater = $this->make_updater();
		$t       = new stdClass();
		$t->checked  = array();
		$t->response = array( 'other' => 'x' );

		$out = $updater->inject_update( $t );
		$this->assertSame( array( 'other' => 'x' ), $out->response );
	}

	/**
	 * @return void
	 */
	public function test_zipball_url_accepted_when_trusted() {
		$GLOBALS['vibe_test_wp_remote_get_handler'] = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'tag_name'    => 'v3.0.0',
						'assets'      => array(),
						'zipball_url' => 'https://api.github.com/repos/o/r/zipball/refs/tags/v3.0.0',
					)
				),
			);
		};

		$updater = $this->make_updater();
		$t       = new stdClass();
		$t->checked  = array( 'vibe-check/vibe-check.php' => '1.0.0' );
		$t->response = array();

		$out = $updater->inject_update( $t );
		$this->assertArrayHasKey( 'vibe-check/vibe-check.php', $out->response );
		$this->assertStringContainsString( 'api.github.com', $out->response['vibe-check/vibe-check.php']->package );
	}

	/**
	 * Live HTTP: set RUN_GITHUB_LIVE_TEST=1 to verify API + asset for this repo (optional CI skip).
	 *
	 * @return void
	 */
	public function test_live_github_latest_release_has_zip_asset() {
		if ( ! getenv( 'RUN_GITHUB_LIVE_TEST' ) ) {
			$this->markTestSkipped( 'Set RUN_GITHUB_LIVE_TEST=1 to run live GitHub API check.' );
		}

		$url = 'https://api.github.com/repos/RegionallyFamous/vibe/releases/latest';
		$ctx = stream_context_create(
			array(
				'http' => array(
					'header'  => "User-Agent: VibeCheck-PHPUnit-Verify\r\nAccept: application/vnd.github+json\r\n",
					'timeout' => 15,
				),
			)
		);
		$raw = @file_get_contents( $url, false, $ctx );
		$this->assertIsString( $raw );
		$this->assertNotSame( '', $raw, 'Empty GitHub response — rate limit or network?' );

		$data = json_decode( $raw, true );
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data['tag_name'] );

		$has_zip = false;
		foreach ( $data['assets'] ?? array() as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'] ) ) {
				$n = strtolower( (string) $asset['name'] );
				if ( strlen( $n ) >= 4 && substr( $n, -4 ) === '.zip' ) {
					$u = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
					if ( $u && preg_match( '#^https://(github\.com|.*\.githubusercontent\.com)/#i', $u ) ) {
						$has_zip = true;
						break;
					}
				}
			}
		}
		$this->assertTrue( $has_zip, 'Latest GitHub release must include a .zip asset on github.com or *.githubusercontent.com for WordPress updater.' );
	}
}
