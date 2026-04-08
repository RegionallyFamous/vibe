#!/usr/bin/env php
<?php
/**
 * Verify GitHub “latest release” data the WordPress updater relies on (no WordPress).
 *
 * Usage:
 *   php scripts/verify-github-release.php [owner] [repo] [installed_version]
 *
 * Examples:
 *   php scripts/verify-github-release.php RegionallyFamous vibe
 *   php scripts/verify-github-release.php RegionallyFamous vibe 1.0.3
 *
 * Optional: GITHUB_TOKEN env reduces rate-limit issues for unauthenticated requests.
 *
 * @package VibeCheck
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

$owner   = isset( $argv[1] ) ? $argv[1] : 'RegionallyFamous';
$repo    = isset( $argv[2] ) ? $argv[2] : 'vibe';
$install = isset( $argv[3] ) ? $argv[3] : '';

$url = 'https://api.github.com/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/releases/latest';

$token = getenv( 'GITHUB_TOKEN' );
$token = ( is_string( $token ) && '' !== $token ) ? $token : '';

$headers = array(
	'User-Agent: VibeCheck-verify-github-release',
	'Accept: application/vnd.github+json',
);
if ( '' !== $token ) {
	$headers[] = 'Authorization: Bearer ' . $token;
}

echo "GET $url\n\n";

$code = 0;
$raw  = '';

if ( function_exists( 'curl_init' ) ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_HTTPHEADER     => $headers,
		)
	);
	$raw = curl_exec( $ch );
	if ( false === $raw ) {
		fwrite( STDERR, 'cURL error: ' . curl_error( $ch ) . "\n" );
		exit( 2 );
	}
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
} else {
	$ctx = stream_context_create(
		array(
			'http' => array(
				'header'  => implode( "\r\n", $headers ),
				'timeout' => 20,
			),
			'ssl'  => array(
				'verify_peer'      => true,
				'verify_peer_name' => true,
			),
		)
	);
	$raw = @file_get_contents( $url, false, $ctx );
	if ( false === $raw ) {
		fwrite( STDERR, "Request failed (network, SSL, or HTTP error).\n" );
		exit( 2 );
	}
	if ( function_exists( 'http_get_last_response_headers' ) ) {
		$rh = http_get_last_response_headers();
		if ( is_array( $rh ) ) {
			foreach ( $rh as $h ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $h, $m ) ) {
					$code = (int) $m[1];
					break;
				}
			}
		}
	}
}

echo "HTTP status: $code\n";

$data = json_decode( $raw, true );
if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
	echo "Body (first 500 bytes):\n" . substr( $raw, 0, 500 ) . "\n";
	fwrite( STDERR, "Invalid JSON.\n" );
	exit( 3 );
}

if ( isset( $data['message'] ) && ! isset( $data['tag_name'] ) ) {
	echo "API message: " . $data['message'] . "\n";
	if ( 404 === $code ) {
		fwrite( STDERR, "No published “latest” release (404). Tags alone are not enough — create a GitHub Release.\n" );
	}
	exit( 4 );
}

$tag = isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '';
$ver = ltrim( substr( $tag, 0, 80 ), 'vV' );

echo "tag_name:  $tag\n";
echo "parsed semver for compare: $ver\n\n";

echo "Assets:\n";
$trusted_zip = null;
foreach ( $data['assets'] ?? array() as $asset ) {
	if ( ! is_array( $asset ) ) {
		continue;
	}
	$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
	$dl   = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
	echo "  - $name\n    $dl\n";
	$low = strtolower( $name );
	if ( strlen( $low ) >= 4 && substr( $low, -4 ) === '.zip' && $dl && is_trusted_github_zip_url( $dl ) ) {
		$trusted_zip = $dl;
	}
}

$zb = isset( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '';
echo "\nzipball_url: " . ( $zb !== '' ? $zb : '(none)' ) . "\n";

if ( null === $trusted_zip && $zb && is_trusted_github_zip_url( $zb ) ) {
	$trusted_zip = $zb;
}

echo "\n--- WordPress updater simulation ---\n";
echo "WordPress matches updates using plugin_basename (folder + main file), e.g. vibe-check/vibe-check.php.\n";
echo "See Settings → Vibe Check for your site’s path, or use Clear update caches if Updates is stale.\n";

if ( null === $trusted_zip ) {
	fwrite( STDERR, "PROBLEM: No trusted .zip package (github.com, *.githubusercontent.com, api.github.com only).\n" );
	exit( 5 );
}

echo "Package URL that would be used: $trusted_zip\n";

if ( $install !== '' ) {
	$cmp = version_compare( $ver, $install, '>' );
	echo "version_compare( '$ver', '$install', '>' ) => " . ( $cmp ? 'true (update offered)' : 'false (no update)' ) . "\n";
	if ( ! $cmp ) {
		echo "(WordPress will only show an update when the installed header version is lower than the release above.)\n";
	}
}

echo "OK.\n";
exit( 0 );

/**
 * Mirror of GitHub_Plugin_Updater::is_trusted_github_package_url (subset for CLI).
 *
 * @param string $url URL.
 * @return bool
 */
function is_trusted_github_zip_url( $url ) {
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
