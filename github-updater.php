<?php
/**
 * GitHub Releases plugin updates (public repo; optional PAT via GITHUB_UPDATER_TOKEN).
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Releases Plugin Updater
 *
 * WordPress loads updates from GitHub releases when a newer semver tag exists
 * and a .zip is attached (or falls back to GitHub’s source zipball).
 */
class GitHub_Plugin_Updater {

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
		$this->cache_key   = 'ghu_' . md5( $this->owner . '|' . $this->repo );

		if ( '' === $this->owner || '' === $this->repo ) {
			_doing_it_wrong( __CLASS__, 'GitHub_Plugin_Updater requires owner and repo.', '1.0.2' );
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Inject GitHub release into the update transient when a newer version exists.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$installed_version = isset( $transient->checked[ $this->plugin_slug ] )
			? $transient->checked[ $this->plugin_slug ]
			: '0.0.0';
		$latest_version    = $this->parse_version( (string) $release['tag_name'] );

		if ( version_compare( $latest_version, $installed_version, '>' ) ) {
			$zip_url = $this->get_asset_zip_url( $release );
			if ( $zip_url ) {
				$transient->response[ $this->plugin_slug ] = $this->build_update_object( $zip_url, $latest_version );
			}
		}

		return $transient;
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

		$obj                = new stdClass();
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
			delete_transient( $this->cache_key );
			self::$memo_set      = false;
			self::$memo_release  = null;
			self::$memo_sig      = '';
		}
	}

	/**
	 * GitHub username / org segment (defensive; used in URL only).
	 *
	 * @param string $raw Raw owner.
	 * @return string
	 */
	private static function sanitize_github_owner( $raw ) {
		$s = strtolower( preg_replace( '/[^a-z0-9-]/', '', (string) $raw ) );
		return substr( $s, 0, 39 );
	}

	/**
	 * Repository name segment.
	 *
	 * @param string $raw Raw repo.
	 * @return string
	 */
	private static function sanitize_github_repo( $raw ) {
		$s = strtolower( preg_replace( '/[^a-z0-9._-]/', '', (string) $raw ) );
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
		if ( in_array( $host, array( 'github.com', 'www.github.com', 'codeload.github.com' ), true ) ) {
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

		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			$out = $cached ? $cached : null;
			self::$memo_set     = true;
			self::$memo_sig     = $sig;
			self::$memo_release = $out;
			return $out;
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
			set_transient( $this->cache_key, array(), 5 * MINUTE_IN_SECONDS );
			self::$memo_set     = true;
			self::$memo_sig     = $sig;
			self::$memo_release = null;
			return null;
		}

		$body    = wp_remote_retrieve_body( $response );
		$release = json_decode( $body, true, 32 );

		if ( JSON_ERROR_NONE !== json_last_error() || empty( $release['tag_name'] ) || ! is_array( $release ) ) {
			set_transient( $this->cache_key, array(), 5 * MINUTE_IN_SECONDS );
			self::$memo_set     = true;
			self::$memo_sig     = $sig;
			self::$memo_release = null;
			return null;
		}

		set_transient( $this->cache_key, $release, $this->cache_ttl );
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
	 * @param string $zip_url Package URL.
	 * @param string $version Parsed version.
	 * @return object
	 */
	private function build_update_object( $zip_url, $version ) {
		$obj              = new stdClass();
		$obj->slug        = $this->plugin_slug;
		$obj->plugin      = $this->plugin_slug;
		$obj->new_version = $version;
		$obj->url         = 'https://github.com/' . $this->owner . '/' . $this->repo;
		$obj->package     = $zip_url;
		$obj->icons       = array();
		$obj->banners     = array();
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
