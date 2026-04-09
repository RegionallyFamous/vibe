#!/usr/bin/env php
<?php
/**
 * Simulate what GitHub_Plugin_Updater does on the server (no WordPress).
 *
 * Uses the same owner/repo sanitization, API URL, zip URL rules, and version
 * comparison as github-updater.php so you can see why Dashboard → Updates
 * does or does not show an offer.
 *
 * Usage:
 *   php scripts/simulate-github-updater.php [options] [owner] [repo] [installed_version]
 *
 * Options:
 *   --json      Machine-readable JSON on stdout (stderr still human hints).
 *   --headers   Print response headers (rate limit, etc.).
 *   --wp-ua     User-Agent closer to WordPress (still no cookies; GitHub only needs a UA).
 *
 * If installed_version is omitted, reads Version from ../vibe-check.php when present.
 *
 * Optional env (same idea as wp-config.php):
 *   GITHUB_TOKEN   Bearer token (like GITHUB_UPDATER_TOKEN on the site).
 *
 * Examples:
 *   php scripts/simulate-github-updater.php
 *   php scripts/simulate-github-updater.php RegionallyFamous vibe 1.0.7
 *   GITHUB_TOKEN=ghp_xxx php scripts/simulate-github-updater.php --headers
 *
 * @package VibeCheck
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

// -----------------------------------------------------------------------------
// Mirrors GitHub_Plugin_Updater (keep in sync when changing the class).
// -----------------------------------------------------------------------------

/**
 * @param string $raw Raw owner.
 * @return string
 */
function simulate_gh_sanitize_owner( $raw ) {
	$s = strtolower( (string) $raw );
	$s = preg_replace( '/[^a-z0-9-]/', '', $s );
	return substr( $s, 0, 39 );
}

/**
 * @param string $raw Raw repo.
 * @return string
 */
function simulate_gh_sanitize_repo( $raw ) {
	$s = strtolower( (string) $raw );
	$s = preg_replace( '/[^a-z0-9._-]/', '', $s );
	return substr( $s, 0, 100 );
}

/**
 * @param string $tag Tag name.
 * @return string
 */
function simulate_gh_parse_version( $tag ) {
	$tag = substr( (string) $tag, 0, 80 );
	return ltrim( $tag, 'vV' );
}

/**
 * @param string $url URL.
 * @return bool
 */
function simulate_gh_is_trusted_package_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || ! preg_match( '#^https://#i', $url ) ) {
		return false;
	}
	$host = parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return false;
	}
	$host = strtolower( $host );
	if ( in_array( $host, array( 'github.com', 'www.github.com', 'codeload.github.com', 'api.github.com' ), true ) ) {
		return true;
	}
	$suffix = '.githubusercontent.com';
	$slen   = strlen( $suffix );
	return strlen( $host ) > $slen && substr( $host, -$slen ) === $suffix;
}

/**
 * @param array $release GitHub release JSON (decoded array).
 * @return string|null
 */
function simulate_gh_pick_package_url( array $release ) {
	foreach ( $release['assets'] ?? array() as $asset ) {
		if ( ! is_array( $asset ) || empty( $asset['name'] ) ) {
			continue;
		}
		$name = strtolower( (string) $asset['name'] );
		if ( strlen( $name ) >= 4 && substr( $name, -4 ) === '.zip' ) {
			$u = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			if ( $u && simulate_gh_is_trusted_package_url( $u ) ) {
				return $u;
			}
		}
	}
	if ( ! empty( $release['zipball_url'] ) ) {
		$zb = (string) $release['zipball_url'];
		if ( simulate_gh_is_trusted_package_url( $zb ) ) {
			return $zb;
		}
	}
	return null;
}

/**
 * Transient key WordPress stores (site uses transients; key is the same formula).
 *
 * @param string $owner Sanitized owner.
 * @param string $repo  Sanitized repo.
 * @return string
 */
function simulate_gh_release_transient_key( $owner, $repo ) {
	return 'ghu_' . md5( simulate_gh_sanitize_owner( $owner ) . '|' . simulate_gh_sanitize_repo( $repo ) );
}

// -----------------------------------------------------------------------------
// CLI
// -----------------------------------------------------------------------------

$args   = array_slice( $argv, 1 );
$flags  = array();
$pos    = array();
foreach ( $args as $a ) {
	if ( '--json' === $a || '--headers' === $a || '--wp-ua' === $a ) {
		$flags[ substr( $a, 2 ) ] = true;
		continue;
	}
	if ( is_string( $a ) && strlen( $a ) >= 2 && '--' === substr( $a, 0, 2 ) ) {
		fwrite( STDERR, "Unknown option: $a\n" );
		exit( 1 );
	}
	$pos[] = $a;
}

$owner_in   = $pos[0] ?? 'RegionallyFamous';
$repo_in    = $pos[1] ?? 'vibe';
$installed  = $pos[2] ?? '';

$root_main = dirname( __DIR__ ) . '/vibe-check.php';
if ( '' === $installed && is_readable( $root_main ) ) {
	$c = file_get_contents( $root_main );
	if ( is_string( $c ) && preg_match( '/^\s*\*\s*Version:\s*([\d.]+)/m', $c, $m ) ) {
		$installed = $m[1];
	}
}
if ( '' === $installed ) {
	$installed = '0.0.0';
}

$san_owner = simulate_gh_sanitize_owner( $owner_in );
$san_repo  = simulate_gh_sanitize_repo( $repo_in );

$api_url = 'https://api.github.com/repos/' . rawurlencode( $san_owner ) . '/' . rawurlencode( $san_repo ) . '/releases/latest';

$token = getenv( 'GITHUB_TOKEN' );
$token = ( is_string( $token ) && '' !== $token ) ? $token : '';

$ua = ! empty( $flags['wp-ua'] )
	? 'WordPress/6.8; https://example.org'
	: 'VibeCheck-simulate-github-updater';

$req_headers = array(
	'Accept: application/vnd.github+json',
	'User-Agent: ' . $ua,
);
if ( '' !== $token ) {
	$req_headers[] = 'Authorization: Bearer ' . $token;
}

$resp_headers = array();
$raw          = '';
$code         = 0;

if ( ! function_exists( 'curl_init' ) ) {
	fwrite( STDERR, "cURL is required for this simulator.\n" );
	exit( 2 );
}

$ch = curl_init( $api_url );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 3,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_HTTPHEADER     => $req_headers,
		CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$resp_headers ) {
			$len = strlen( $line );
			if ( false !== strpos( $line, ':' ) ) {
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$resp_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
			}
			return $len;
		},
	)
);
$raw = curl_exec( $ch );
if ( false === $raw ) {
	fwrite( STDERR, 'cURL error: ' . curl_error( $ch ) . "\n" );
	exit( 2 );
}
$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

$out = array(
	'input_owner'           => $owner_in,
	'input_repo'            => $repo_in,
	'sanitized_owner'       => $san_owner,
	'sanitized_repo'        => $san_repo,
	'api_url'               => $api_url,
	'http_code'             => $code,
	'installed_version'     => $installed,
	'wordpress_transient_key' => simulate_gh_release_transient_key( $owner_in, $repo_in ),
);

if ( ! empty( $flags['headers'] ) && ! empty( $flags['json'] ) ) {
	$out['response_headers'] = $resp_headers;
}

if ( ! empty( $flags['headers'] ) && empty( $flags['json'] ) ) {
	echo "--- Response headers (rate limit) ---\n";
	foreach ( array( 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'x-github-request-id' ) as $h ) {
		if ( isset( $resp_headers[ $h ] ) ) {
			echo "{$h}: {$resp_headers[ $h ]}\n";
		}
	}
	echo "\n";
}

if ( 200 !== $code ) {
	$out['merge_result']       = 'no_injection';
	$out['reason']             = 'bad_http';
	$out['would_offer_update'] = false;
	if ( ! empty( $flags['json'] ) ) {
		$out['body_preview'] = substr( $raw, 0, 500 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- CLI, no WordPress.
		echo json_encode( $out ) . "\n";
	} else {
		echo "GET $api_url\n";
		echo "HTTP $code (WordPress updater caches failures ~5 minutes in transient {$out['wordpress_transient_key']})\n";
		if ( 403 === $code ) {
			echo "Typical cause: unauthenticated GitHub API rate limit (shared host IP). Use GITHUB_TOKEN=… for this script or GITHUB_UPDATER_TOKEN in wp-config.php on the site.\n";
		}
		if ( 404 === $code ) {
			echo "Typical cause: no published “latest” release (draft-only, prerelease-only, or tag without Release).\n";
		}
		echo substr( $raw, 0, 800 ) . "\n";
	}
	exit( 4 === $code ? 4 : 2 );
}

$data = json_decode( $raw, true );
if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || empty( $data['tag_name'] ) ) {
	$out['merge_result']       = 'no_injection';
	$out['reason']             = 'bad_json';
	$out['would_offer_update'] = false;
	if ( ! empty( $flags['json'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- CLI, no WordPress.
		echo json_encode( $out ) . "\n";
	} else {
		echo "Invalid release JSON or missing tag_name.\n";
	}
	exit( 3 );
}

$latest_ver = simulate_gh_parse_version( (string) $data['tag_name'] );
$package    = simulate_gh_pick_package_url( $data );
$cmp        = version_compare( $latest_ver, $installed, '>' );

$out['tag_name']             = $data['tag_name'];
$out['parsed_latest']        = $latest_ver;
$out['package_url']          = $package;
$out['package_url_trusted']  = null !== $package;
$out['version_compare_gt']   = $cmp;
$out['would_offer_update']   = $cmp && null !== $package;
$out['merge_result']         = $out['would_offer_update'] ? 'in_response' : ( null === $package ? 'no_trusted_zip' : 'no_update_needed' );
$out['installed_newer_than_github'] = version_compare( $installed, $latest_ver, '>' );

if ( ! empty( $flags['json'] ) ) {
	if ( ! empty( $flags['headers'] ) ) {
		$out['response_headers'] = $resp_headers;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- CLI tool, not WP runtime.
	echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	echo "=== Simulate GitHub_Plugin_Updater (no WordPress) ===\n\n";
	echo "Configured owner/repo (raw):     {$owner_in} / {$repo_in}\n";
	echo "Sanitized (as in plugin):        {$san_owner} / {$san_repo}\n";
	echo "GET:                             $api_url\n";
	echo "Transient key (plugin cache):    {$out['wordpress_transient_key']}\n\n";
	echo "Installed version (simulated):   $installed\n";
	echo "Latest release tag:              {$data['tag_name']}\n";
	echo "Parsed for version_compare:      $latest_ver\n";
	echo "version_compare( latest, installed, '>' ): " . ( $cmp ? 'true' : 'false' ) . "\n\n";
	if ( null === $package ) {
		echo "Trusted .zip / zipball URL:      (none — updater would NOT inject an update)\n";
	} else {
		echo "Trusted package URL:             $package\n";
	}
	echo "\n--- WordPress behavior ---\n";
	if ( null === $package ) {
		echo "Result: No package → merge_github_release_into_transient returns unchanged (no update row).\n";
	} elseif ( $cmp ) {
		echo "Result: Update would appear in update_plugins->response for your plugin basename, with slug `vibe-check` and new_version `$latest_ver`.\n";
	} else {
		echo "Result: No update (installed is same or newer). Updater may set no_update for the plugin.\n";
		if ( version_compare( $installed, $latest_ver, '>' ) ) {
			echo "\n>>> Installed ($installed) is NEWER than GitHub latest ($latest_ver). Publish a GitHub Release with a higher tag (e.g. v$installed) or the updater will never offer a downgrade.\n";
		}
	}
	echo "\nTip: On the server, plugin basename must be in update_plugins->checked (e.g. vibe-check/vibe-check.php).\n";
}

exit( $out['would_offer_update'] || ( null !== $package && ! $cmp ) ? 0 : 5 );
