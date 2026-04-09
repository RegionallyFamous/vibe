<?php
/**
 * GitHub Releases plugin updates (public repo; optional PAT via GITHUB_UPDATER_TOKEN).
 *
 * @package RegionallyFamous\UpdaterMcUpdateface
 */

namespace RegionallyFamous\UpdaterMcUpdateface;

/**
 * Updater McUpdateface — GitHub Releases plugin updates.
 *
 * WordPress loads updates from GitHub releases when a newer semver tag exists
 * and a .zip is attached (or falls back to GitHub’s source zipball).
 */
class UpdaterMcUpdateface {

	/**
	 * Same-request memo for inject_update + plugins_api (avoids duplicate HTTP).
	 *
	 * @var bool
	 */
	private static $memo_set = false;

	/**
	 * @var array|null
	 */
	private static $memo_release;

	/**
	 * @var string
	 */
	private static $memo_sig = '';

	/**
	 * @var string
	 */
	private $plugin_file;

	/**
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * @var string
	 */
	private $owner;

	/**
	 * @var string
	 */
	private $repo;

	/**
	 * @var string
	 */
	private $token;

	/**
	 * @var string
	 */
	private $cache_key;

	/**
	 * @var int
	 */
	private $cache_ttl = 12 * HOUR_IN_SECONDS;

	/**
	 * Cached value when the GitHub request failed or JSON was invalid.
	 *
	 * Do not use an empty array(): in PHP it is truthy, so callers testing `if ( ! $release )`
	 * would mis-handle it. A short string keeps failed fetches distinct from a real release payload.
	 *
	 * @var string
	 */
	private const RELEASE_FETCH_MISS = '_vibe_check_ghu_fetch_miss_';

	/**
	 * @param string $plugin_file Main plugin file path.
	 * @param array  $config      Keys: owner, repo, token (optional).
	 */
	public function __construct( $plugin_file, array $config ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_slug = plugin_basename( $plugin_file );
		$this->owner       = self::sanitize_github_owner( isset( $config['owner'] ) ? (string) $config['owner'] : '' );
		$this->repo        = self::sanitize_github_repo( isset( $config['repo'] ) ? (string) $config['repo'] : '' );
		$tok               = isset( $config['token'] ) ? trim( (string) $config['token'] ) : '';
		$this->token       = strlen( $tok ) > 512 ? substr( $tok, 0, 512 ) : $tok;

		if ( '' === $this->owner || '' === $this->repo ) {
			_doing_it_wrong( __CLASS__, 'UpdaterMcUpdateface requires owner and repo.', '1.0.0' );
			return;
		}

		$this->cache_key = self::release_transient_key( $this->owner, $this->repo );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		// Some hosts never re-fire pre_set; merging on read still uses the cached GitHub JSON transient.
		// Late priority so other code does not strip our entry afterward; still before the update UI renders.
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_update_on_read' ), 999, 1 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * @param object|false $transient Update transient or false.
	 * @return object|false
	 */
	public function inject_update_on_read( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		return $this->merge_github_release_into_transient( $transient );
	}

	/**
	 * Before WordPress saves the update_plugins site transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		return $this->merge_github_release_into_transient( $transient );
	}

	/**
	 * Attach GitHub release to response / no_update for this plugin’s basename(s).
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	private function merge_github_release_into_transient( $transient ) {
		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = $this->parse_version( (string) $release['tag_name'] );
		$zip_url        = $this->get_asset_zip_url( $release );
		if ( ! $zip_url ) {
			return $transient;
		}

		$short_slug = $this->plugin_dir_slug();
		$slugs      = $this->collect_slugs_for_this_plugin( $transient->checked );

		$gh_debug = ( defined( 'VIBE_CHECK_UPDATER_DEBUG' ) && VIBE_CHECK_UPDATER_DEBUG )
			|| ( defined( 'RF_GITHUB_RELEASE_UPDATER_DEBUG' ) && RF_GITHUB_RELEASE_UPDATER_DEBUG );
		if ( array() === $slugs && $gh_debug && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$keys = array_keys( $transient->checked );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug.
			error_log(
				'RF GitHub release updater: no matching basename in update_plugins->checked for this plugin file. ' .
				'Expected key like `' . $this->plugin_slug . '`. plugin_basename=' . $this->plugin_slug .
				' file=' . $this->plugin_file .
				' checked_keys_sample=' . implode( ',', array_slice( $keys, 0, 15 ) )
			);
		}

		foreach ( $slugs as $slug ) {
			if ( ! isset( $transient->checked[ $slug ] ) ) {
				continue;
			}
			$installed_version = $transient->checked[ $slug ];
			if ( version_compare( $latest_version, $installed_version, '>' ) ) {
				$transient->response[ $slug ] = $this->build_update_object( $zip_url, $latest_version, $short_slug, $slug );
			} else {
				$transient->no_update[ $slug ] = $this->build_no_update_object( $installed_version, $short_slug, $slug );
			}
		}

		return $transient;
	}

	/**
	 * Directory slug (e.g. vibe-check), not plugin_basename.
	 *
	 * @return string
	 */
	private function plugin_dir_slug() {
		return str_replace( '/' . basename( $this->plugin_file ), '', $this->plugin_slug );
	}

	/**
	 * Transient key used to cache the GitHub /releases/latest JSON (for delete_site_transient).
	 *
	 * @param string $owner Sanitized owner (same rules as config).
	 * @param string $repo  Sanitized repo.
	 * @return string
	 */
	public static function release_transient_key( $owner, $repo ) {
		return 'ghu_' . md5( self::sanitize_github_owner( $owner ) . '|' . self::sanitize_github_repo( $repo ) );
	}

	/**
	 * Clear same-request memo after external cache bust (Settings link or tests).
	 *
	 * @return void
	 */
	public static function clear_static_memo() {
		self::$memo_set     = false;
		self::$memo_release = null;
		self::$memo_sig     = '';
	}

	/**
	 * Every plugin basename in $checked that resolves to this plugin’s main file (handles renamed folders, symlinks).
	 *
	 * @param array<string, string> $checked update_plugins->checked.
	 * @return string[]
	 */
	private function collect_slugs_for_this_plugin( array $checked ) {
		$main    = basename( $this->plugin_file );
		$pattern = '#/' . preg_quote( $main, '#' ) . '$#';
		$out     = array();

		$can_norm = function_exists( 'wp_normalize_path' )
			&& defined( 'WP_PLUGIN_DIR' )
			&& is_string( WP_PLUGIN_DIR )
			&& '' !== WP_PLUGIN_DIR;

		$our_norm = '';
		$root_n   = '';
		if ( $can_norm ) {
			$our_norm = wp_normalize_path( $this->plugin_file );
			$root_n   = rtrim( wp_normalize_path( WP_PLUGIN_DIR ), '/\\' );
		}

		$our_rp  = @realpath( $this->plugin_file );
		$root_rp = ( $can_norm || ( defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) && '' !== WP_PLUGIN_DIR ) )
			? @realpath( WP_PLUGIN_DIR )
			: false;

		foreach ( array_keys( $checked ) as $slug ) {
			if ( ! is_string( $slug ) || ! preg_match( $pattern, $slug ) ) {
				continue;
			}
			$rel = str_replace( '\\', '/', $slug );
			$hit = false;

			if ( '' !== $our_norm && '' !== $root_n ) {
				$candidate = wp_normalize_path( $root_n . '/' . $rel );
				if ( $candidate === $our_norm ) {
					$hit = true;
				}
			}
			if ( ! $hit && $our_rp && $root_rp ) {
				$path = $root_rp . '/' . $rel;
				$rp   = @realpath( $path );
				if ( $rp && $rp === $our_rp ) {
					$hit = true;
				}
			}
			if ( $hit ) {
				$out[] = $slug;
			}
		}

		if ( array() === $out && isset( $checked[ $this->plugin_slug ] ) ) {
			$out[] = $this->plugin_slug;
		}

		/**
		 * Plugin basenames (keys in `update_plugins->checked`) that should receive GitHub update metadata.
		 *
		 * @param string[]              $slugs       Detected basenames (e.g. `vibe-check/vibe-check.php`).
		 * @param array<string, string> $checked     Full `checked` map from the update transient.
		 * @param string                $plugin_file Absolute path to this plugin’s main file (`__FILE__`).
		 */
		$filtered = apply_filters( 'rf_wp_github_release_updater_collect_slugs', $out, $checked, $this->plugin_file );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', (array) $filtered )
				)
			)
		);
	}

	/**
	 * Plugin row “View details” modal.
	 *
	 * @param mixed  $result API result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = str_replace( '/' . basename( $this->plugin_file ), '', $this->plugin_slug );
		if ( ! isset( $args->slug ) || $args->slug !== $slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$zip_url = $this->get_asset_zip_url( $release );
		if ( ! $zip_url ) {
			return $result;
		}

		$version = $this->parse_version( (string) $release['tag_name'] );
		$info    = get_plugin_data( $this->plugin_file );

		$obj                = new \stdClass();
		$obj->name          = isset( $info['Name'] ) ? $info['Name'] : $this->repo;
		$obj->slug          = $slug;
		$obj->version       = $version;
		$obj->author        = isset( $info['Author'] ) ? $info['Author'] : $this->owner;
		$obj->homepage      = 'https://github.com/' . $this->owner . '/' . $this->repo;
		$obj->requires      = isset( $info['RequiresWP'] ) ? $info['RequiresWP'] : '6.0';
		$obj->tested        = get_bloginfo( 'version' );
		$obj->last_updated  = isset( $release['published_at'] ) ? $release['published_at'] : '';
		$obj->download_link = $zip_url;
		$obj->sections      = array(
			'description' => isset( $info['Description'] ) ? $info['Description'] : '',
			'changelog'   => $this->format_changelog( isset( $release['body'] ) ? (string) $release['body'] : '' ),
		);

		return $obj;
	}

	/**
	 * Clear release cache after this plugin is updated.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Hook options.
	 */
	public function clear_cache( $upgrader, array $options ) {
		if (
			isset( $options['action'], $options['type'] ) &&
			'update' === $options['action'] &&
			'plugin' === $options['type'] &&
			in_array( $this->plugin_slug, (array) ( $options['plugins'] ?? array() ), true )
		) {
			delete_site_transient( $this->cache_key );
			self::clear_static_memo();
		}
	}

	/**
	 * GitHub username / org segment (defensive; used in URL only).
	 *
	 * @param string $raw Raw owner.
	 * @return string
	 */
	private static function sanitize_github_owner( $raw ) {
		$s = strtolower( (string) $raw );
		$s = preg_replace( '/[^a-z0-9-]/', '', $s );
		return substr( $s, 0, 39 );
	}

	/**
	 * Repository name segment.
	 *
	 * @param string $raw Raw repo.
	 * @return string
	 */
	private static function sanitize_github_repo( $raw ) {
		$s = strtolower( (string) $raw );
		$s = preg_replace( '/[^a-z0-9._-]/', '', $s );
		return substr( $s, 0, 100 );
	}

	/**
	 * Only trust package URLs on GitHub-controlled hosts (malicious API JSON SSRF guard).
	 *
	 * @param string $url Candidate download URL.
	 * @return bool
	 */
	private function is_trusted_github_package_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		if ( 0 !== stripos( $url, 'https://' ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );
		if ( in_array( $host, array( 'github.com', 'www.github.com', 'codeload.github.com', 'api.github.com' ), true ) ) {
			return true;
		}
		$suffix = '.githubusercontent.com';
		$slen   = strlen( $suffix );
		if ( strlen( $host ) > $slen && substr( $host, -$slen ) === $suffix ) {
			return true;
		}
		return false;
	}

	/**
	 * @return array|null
	 */
	private function get_latest_release() {
		$sig = $this->owner . '/' . $this->repo;
		if ( self::$memo_set && self::$memo_sig === $sig ) {
			return self::$memo_release;
		}

		$cached = get_site_transient( $this->cache_key );
		if ( false !== $cached ) {
			if ( self::RELEASE_FETCH_MISS === $cached ) {
				self::$memo_set     = true;
				self::$memo_sig     = $sig;
				self::$memo_release = null;
				return null;
			}
			if ( is_array( $cached ) && ! empty( $cached['tag_name'] ) ) {
				self::$memo_set     = true;
				self::$memo_sig     = $sig;
				self::$memo_release = $cached;
				return $cached;
			}
			// Legacy failure cache used `array()` (truthy in PHP) or corrupt payload — drop and refetch.
			delete_site_transient( $this->cache_key );
		}

		$url      = 'https://api.github.com/repos/' . rawurlencode( $this->owner ) . '/' . rawurlencode( $this->repo ) . '/releases/latest';
		$args     = array(
			'headers'            => $this->request_headers(),
			'timeout'            => 10,
			'redirection'        => 2,
			'sslverify'          => true,
			'reject_unsafe_urls' => true,
		);
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$gh_debug = ( defined( 'VIBE_CHECK_UPDATER_DEBUG' ) && VIBE_CHECK_UPDATER_DEBUG )
				|| ( defined( 'RF_GITHUB_RELEASE_UPDATER_DEBUG' ) && RF_GITHUB_RELEASE_UPDATER_DEBUG );
			if ( $gh_debug && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				if ( is_wp_error( $response ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug.
					error_log( 'RF GitHub release updater: ' . $response->get_error_message() );
				} else {
					$code = (int) wp_remote_retrieve_response_code( $response );
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug.
					error_log( 'RF GitHub release updater: HTTP ' . $code . ' ' . $url );
					if ( 403 === $code ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug.
						error_log( 'RF GitHub release updater: HTTP 403 is often GitHub API rate limit for unauthenticated requests; define GITHUB_UPDATER_TOKEN in wp-config.php or wait ~1 hour.' );
					}
					if ( 404 === $code ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug.
						error_log( 'RF GitHub release updater: HTTP 404 means no published “latest” release (draft/prerelease-only/tags are not enough).' );
					}
				}
			}
			set_site_transient( $this->cache_key, self::RELEASE_FETCH_MISS, 5 * MINUTE_IN_SECONDS );
			self::$memo_set     = true;
			self::$memo_sig     = $sig;
			self::$memo_release = null;
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 2097152 ) {
			set_site_transient( $this->cache_key, self::RELEASE_FETCH_MISS, 5 * MINUTE_IN_SECONDS );
			self::$memo_set     = true;
			self::$memo_sig     = $sig;
			self::$memo_release = null;
			return null;
		}

		$release = json_decode( $body, true, 32 );

		if ( JSON_ERROR_NONE !== json_last_error() || empty( $release['tag_name'] ) || ! is_array( $release ) ) {
			set_site_transient( $this->cache_key, self::RELEASE_FETCH_MISS, 5 * MINUTE_IN_SECONDS );
			self::$memo_set     = true;
			self::$memo_sig     = $sig;
			self::$memo_release = null;
			return null;
		}

		set_site_transient( $this->cache_key, $release, $this->cache_ttl );
		self::$memo_set     = true;
		self::$memo_sig     = $sig;
		self::$memo_release = $release;
		return $release;
	}

	/**
	 * @param array $release GitHub release payload.
	 * @return string|null
	 */
	private function get_asset_zip_url( array $release ) {
		foreach ( $release['assets'] ?? array() as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) ) {
				continue;
			}
			$name = strtolower( (string) $asset['name'] );
			if ( strlen( $name ) >= 4 && substr( $name, -4 ) === '.zip' ) {
				$u = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
				if ( $u && $this->is_trusted_github_package_url( $u ) ) {
					return $u;
				}
			}
		}

		if ( ! empty( $release['zipball_url'] ) ) {
			$zb = (string) $release['zipball_url'];
			if ( $this->is_trusted_github_package_url( $zb ) ) {
				return $zb;
			}
		}

		return null;
	}

	/**
	 * @param string $zip_url      Package URL.
	 * @param string $version      Parsed version.
	 * @param string $short_slug   Plugin directory slug (WordPress core expects this, not basename).
	 * @param string $plugin_file  plugin_basename main file.
	 * @return object
	 */
	private function build_update_object( $zip_url, $version, $short_slug, $plugin_file ) {
		$obj              = new \stdClass();
		$obj->id          = $this->owner . '/' . $this->repo;
		$obj->slug        = $short_slug;
		$obj->plugin      = $plugin_file;
		$obj->new_version = $version;
		$obj->url         = 'https://github.com/' . $this->owner . '/' . $this->repo;
		$obj->package     = $zip_url;
		$obj->icons       = array();
		$obj->banners     = array();
		$obj->banners_rtl = array();
		return $obj;
	}

	/**
	 * @param string $installed_version Current header version.
	 * @param string $short_slug        Directory slug.
	 * @param string $plugin_file       plugin_basename.
	 * @return object
	 */
	private function build_no_update_object( $installed_version, $short_slug, $plugin_file ) {
		$obj              = new \stdClass();
		$obj->id          = $this->owner . '/' . $this->repo;
		$obj->slug        = $short_slug;
		$obj->plugin      = $plugin_file;
		$obj->new_version = $installed_version;
		$obj->url         = 'https://github.com/' . $this->owner . '/' . $this->repo;
		$obj->package     = false;
		$obj->icons       = array();
		$obj->banners     = array();
		$obj->banners_rtl = array();
		$obj->tested      = get_bloginfo( 'version' );
		return $obj;
	}

	/**
	 * @return array<string, string>
	 */
	private function request_headers() {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
		);
		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}

	/**
	 * @param string $tag Tag name (e.g. v1.2.3).
	 * @return string
	 */
	private function parse_version( $tag ) {
		$tag = substr( (string) $tag, 0, 80 );
		return ltrim( $tag, 'vV' );
	}

	/**
	 * @param string $body Release notes (markdown).
	 * @return string
	 */
	private function format_changelog( $body ) {
		if ( '' === $body ) {
			return '<p>See release notes on GitHub.</p>';
		}

		if ( strlen( $body ) > 65536 ) {
			$body = substr( $body, 0, 65536 ) . "\n\n…";
		}

		$body = htmlspecialchars( $body, ENT_QUOTES, 'UTF-8' );
		$body = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $body );
		$body = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $body );
		$body = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $body );
		$body = preg_replace( '/(<li>.+<\/li>(\n|$))+/', '<ul>$0</ul>', $body );
		$body = nl2br( $body );

		return is_string( $body ) ? $body : '<p>See release notes on GitHub.</p>';
	}
}
