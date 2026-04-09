<?php
/**
 * WordPress stubs for {@see GitHub_Plugin_Updater} unit tests.
 *
 * @package VibeCheck
 */

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! isset( $GLOBALS['vibe_test_transients'] ) || ! is_array( $GLOBALS['vibe_test_transients'] ) ) {
	$GLOBALS['vibe_test_transients'] = array();
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/var/www/wp-content/plugins/' );
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		return preg_replace( '#/{2,}#', '/', $path );
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WP_Error for stubs.
	 */
	class WP_Error {
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * @param string $function_name Function.
	 * @param string $message     Message.
	 * @param string $version     Version.
	 */
	function _doing_it_wrong( $function_name, $message, $version ) {
		unset( $function_name, $message, $version );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string   $hook            Hook.
	 * @param callable $callback        Callback.
	 * @param int      $priority        Priority.
	 * @param int      $accepted_args   Args.
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * @param string $file Path to main plugin file.
	 * @return string Slug like vibe-check/vibe-check.php
	 */
	function plugin_basename( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		if ( preg_match( '#/plugins/([^/]+/[^/]+\.php)$#', $file, $m ) ) {
			return $m[1];
		}
		$dir  = basename( dirname( $file ) );
		$base = basename( $file );
		return $dir . '/' . $base;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $key Key.
	 * @return mixed
	 */
	function get_transient( $key ) {
		if ( isset( $GLOBALS['vibe_test_transients'][ $key ] ) ) {
			return $GLOBALS['vibe_test_transients'][ $key ];
		}
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL (ignored in stub).
	 * @return bool
	 */
	function set_transient( $key, $value, $ttl = 0 ) {
		unset( $ttl );
		$GLOBALS['vibe_test_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $key Key.
	 * @return bool
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['vibe_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	/**
	 * @param string $key Key.
	 * @return mixed
	 */
	function get_site_transient( $key ) {
		return get_transient( $key );
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL.
	 * @return bool
	 */
	function set_site_transient( $key, $value, $ttl = 0 ) {
		return set_transient( $key, $value, $ttl );
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	/**
	 * @param string $key Key.
	 * @return bool
	 */
	function delete_site_transient( $key ) {
		return delete_transient( $key );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Args.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_remote_get( $url, $args = array() ) {
		unset( $args );
		if ( isset( $GLOBALS['vibe_test_wp_remote_get_handler'] ) && is_callable( $GLOBALS['vibe_test_wp_remote_get_handler'] ) ) {
			return call_user_func( $GLOBALS['vibe_test_wp_remote_get_handler'], $url );
		}
		return array(
			'response' => array( 'code' => 599 ),
			'body'     => '',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array<string, mixed>|WP_Error $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array<string, mixed>|WP_Error $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	/**
	 * @param string $plugin_file Path (unused in stub).
	 * @param bool   $markup      Markup.
	 * @param bool   $translate   Translate.
	 * @return array<string, string>
	 */
	function get_plugin_data( $plugin_file, $markup = true, $translate = true ) {
		unset( $plugin_file, $markup, $translate );
		return array(
			'Name'        => 'Vibe Check Test',
			'RequiresWP'  => '6.5',
			'Description' => 'Test plugin',
			'Author'      => 'Test',
		);
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * @param string $show What to show.
	 * @return string
	 */
	function get_bloginfo( $show ) {
		if ( 'version' === $show ) {
			return '6.5';
		}
		if ( 'url' === $show ) {
			return 'https://example.org';
		}
		return '';
	}
}

/**
 * Reset static memo on GitHub_Plugin_Updater between tests.
 *
 * @return void
 */
function vibe_test_github_updater_reset_static_memo() {
	if ( ! class_exists( 'GitHub_Plugin_Updater', false ) ) {
		return;
	}
	$ref = new ReflectionClass( 'GitHub_Plugin_Updater' );
	foreach ( array( 'memo_set', 'memo_sig', 'memo_release' ) as $name ) {
		$p = $ref->getProperty( $name );
		if ( 'memo_set' === $name ) {
			$p->setValue( null, false );
		} elseif ( 'memo_sig' === $name ) {
			$p->setValue( null, '' );
		} else {
			$p->setValue( null, null );
		}
	}
}
