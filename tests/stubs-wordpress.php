<?php
/**
 * Minimal stubs for WordPress helpers used by quiz data (unit tests only).
 *
 * @package VibeCheck
 */

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param mixed $str String.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param mixed $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param mixed   $string       String.
	 * @param bool    $remove_breaks Remove line breaks.
	 * @return string
	 */
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = strip_tags( (string) $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string        $url       URL.
	 * @param string[]|null $protocols Allowed schemes.
	 * @return string
	 */
	function esc_url_raw( $url, $protocols = null ) {
		$url = trim( (string) $url );
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return '';
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	/**
	 * @param string|false $url URL.
	 * @return bool
	 */
	function wp_http_validate_url( $url ) {
		return is_string( $url ) && (bool) preg_match( '#^https?://#i', $url );
	}
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	/**
	 * @param mixed $str   String.
	 * @param bool  $strip Strip invalid.
	 * @return string
	 */
	function wp_check_invalid_utf8( $str, $strip = false ) {
		return is_string( $str ) ? $str : '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return (int) abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/**
	 * @param mixed $post_id Attachment post ID.
	 * @return bool True for test IDs 42 and 999 only.
	 */
	function wp_attachment_is_image( $post_id ) {
		return in_array( (int) $post_id, array( 42, 999 ), true );
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	/**
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Size.
	 * @return string|false
	 */
	function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail' ) {
		unset( $size );
		$id = (int) $attachment_id;
		if ( in_array( $id, array( 42, 999 ), true ) ) {
			return 'https://example.com/wp-content/uploads/test-' . $id . '.jpg';
		}
		return false;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		if ( (int) $post_id === 42 && '_wp_attachment_image_alt' === $key ) {
			return 'Alt from meta';
		}
		return '';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data    Data.
	 * @param int   $options Options.
	 * @param int   $depth   Depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		$json = json_encode( $data, $options );
		return false === $json ? false : $json;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string   $hook            Hook name.
	 * @param callable $callback        Callback.
	 * @param int      $priority        Priority.
	 * @param int      $accepted_args   Accepted args.
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $tag   Hook name.
	 * @param mixed  $value Value.
	 * @param mixed  ...$args Extra args (ignored in stub).
	 * @return mixed
	 */
	function apply_filters( $tag, $value, ...$args ) {
		unset( $tag, $args );
		return $value;
	}
}
