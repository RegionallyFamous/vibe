<?php
/**
 * REST OG image + wp_head meta for ?quiz_result=
 *
 * @package VibeCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursively find first vibe-check/quiz block attrs.
 *
 * @param array $blocks Parsed blocks.
 * @return array|null
 */
function vibe_check_find_quiz_attrs( $blocks ) {
	foreach ( $blocks as $block ) {
		if ( isset( $block['blockName'] ) && 'vibe-check/quiz' === $block['blockName'] ) {
			return isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = vibe_check_find_quiz_attrs( $block['innerBlocks'] );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Parsed block attrs for the first quiz block, with transient cache keyed by content hash.
 *
 * @param WP_Post $post Post.
 * @return array|null Block attrs or null if no quiz block.
 */
function vibe_check_get_quiz_attrs_from_post( WP_Post $post ) {
	$hash   = md5( (string) $post->post_content );
	$ckey   = 'vibe_check_qa_' . (int) $post->ID . '_' . $hash;
	$cached = get_transient( $ckey );
	if ( false !== $cached ) {
		if ( is_string( $cached ) && '_vibe_none_' === $cached ) {
			return null;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	$attrs = vibe_check_find_quiz_attrs( parse_blocks( $post->post_content ) );
	if ( null === $attrs ) {
		set_transient( $ckey, '_vibe_none_', DAY_IN_SECONDS );
	} else {
		set_transient( $ckey, $attrs, DAY_IN_SECONDS );
	}
	return $attrs;
}

/**
 * Sanitized quiz for the first block in a post, transient-cached (same key basis as attrs).
 *
 * @param WP_Post $post Post.
 * @return array{ title: string, subtitle: string, questions: array, results: array }|null Null if no quiz block.
 */
function vibe_check_get_sanitized_quiz_from_post( WP_Post $post ) {
	$attrs = vibe_check_get_quiz_attrs_from_post( $post );
	if ( null === $attrs ) {
		return null;
	}
	$hash   = md5( (string) $post->post_content );
	$ckey   = 'vibe_check_sq_' . (int) $post->ID . '_' . $hash;
	$cached = get_transient( $ckey );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$quiz = vibe_check_sanitize_quiz_payload( $attrs );
	set_transient( $ckey, $quiz, DAY_IN_SECONDS );
	return $quiz;
}

/**
 * Whether the post exposes a valid quiz result id (for OG meta).
 *
 * @param int    $post_id   Post ID.
 * @param string $result_id Result key.
 * @return bool
 */
function vibe_check_is_valid_result_for_post( $post_id, $result_id ) {
	return null !== vibe_check_get_quiz_result_context( $post_id, $result_id );
}

/**
 * Quiz + result row for OG / Twitter text when ?quiz_result= is valid.
 *
 * @param int    $post_id   Post ID.
 * @param string $result_id Result key.
 * @return array{ quiz_title: string, result: array<string, string> }|null
 */
function vibe_check_get_quiz_result_context( $post_id, $result_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return null;
	}
	$sanitized = vibe_check_get_sanitized_quiz_from_post( $post );
	if ( null === $sanitized ) {
		return null;
	}
	$result_id = sanitize_key( (string) $result_id );
	foreach ( $sanitized['results'] as $r ) {
		if ( isset( $r['id'] ) && (string) $r['id'] === $result_id ) {
			return array(
				'quiz_title' => $sanitized['title'],
				'result'     => $r,
			);
		}
	}
	return null;
}

/**
 * Client IP for OG rate limiting. Uses REMOTE_ADDR unless the site opts in via
 * `vibe_check_trust_proxy_headers` (e.g. behind Cloudflare / a trusted load balancer).
 *
 * @return string
 */
function vibe_check_og_rate_limit_client_ip() {
	$trust = (bool) apply_filters( 'vibe_check_trust_proxy_headers', false );
	if ( ! $trust ) {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
	}

	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$parts     = array_map( 'trim', explode( ',', $forwarded ) );
		$first     = isset( $parts[0] ) ? $parts[0] : '';
		if ( $first && filter_var( $first, FILTER_VALIDATE_IP ) ) {
			return $first;
		}
	}

	return isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: 'unknown';
}

/**
 * Simple per-IP rate limit for OG endpoint (abuse / DoS).
 *
 * @return bool True if allowed.
 */
function vibe_check_og_rate_limit_ok() {
	$ip  = vibe_check_og_rate_limit_client_ip();
	$key = 'vibe_check_og_rl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 90 ) {
		return false;
	}
	set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
	return true;
}

/**
 * Bump OG JPEG cache generation when a post with a quiz block is saved (invalidates cached JPEGs).
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function vibe_check_bump_og_jpeg_cache_gen( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	if ( false === strpos( $post->post_content, 'wp:vibe-check/quiz' ) ) {
		return;
	}
	$gen = (int) get_post_meta( $post_id, '_vibe_check_og_jpeg_gen', true );
	update_post_meta( $post_id, '_vibe_check_og_jpeg_gen', $gen + 1 );
}
add_action( 'save_post', 'vibe_check_bump_og_jpeg_cache_gen', 10, 2 );

/**
 * Text safe for GD imagettftext (UTF-8, no control chars).
 *
 * @param string $text Text.
 * @param int    $max Max length.
 * @return string
 */
function vibe_check_sanitize_text_for_gd( $text, $max = 2000 ) {
	$text = wp_check_invalid_utf8( (string) $text, true );
	$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
	return vibe_check_truncate_utf8( $text, $max );
}

/**
 * Center X for imagettftext given string and font size.
 *
 * @param string $text Text.
 * @param string $font Font path.
 * @param int    $size Size.
 * @param int    $img_width Image width.
 * @return int Left X.
 */
function vibe_check_center_text_x( $text, $font, $size, $img_width ) {
	if ( ! is_readable( $font ) ) {
		return (int) max( 10, $img_width / 2 - strlen( $text ) * $size * 0.25 );
	}
	$box = imagettfbbox( $size, 0, $font, $text );
	if ( false === $box ) {
		return (int) ( $img_width / 2 );
	}
	$w = abs( $box[2] - $box[0] );
	return (int) ( ( $img_width - $w ) / 2 );
}

/**
 * Draw wrapped lines with imagettftext; returns next Y.
 *
 * @param resource|\GdImage $img Image.
 * @param string              $font Font path.
 * @param int                 $size Size.
 * @param int                 $color Color.
 * @param string              $text Text.
 * @param int                 $x Left.
 * @param int                 $y Top baseline start.
 * @param int                 $max_chars Per line.
 * @param int                 $line_height Line height.
 * @return int Next Y.
 */
function vibe_check_draw_wrapped_text( $img, $font, $size, $color, $text, $x, $y, $max_chars, $line_height ) {
	$lines = explode( "\n", wordwrap( $text, $max_chars, "\n", true ) );
	$cy    = $y;
	foreach ( $lines as $line ) {
		if ( is_readable( $font ) ) {
			imagettftext( $img, $size, 0, $x, $cy, $color, $font, $line );
		} else {
			imagestring( $img, 3, $x, $cy - 12, substr( $line, 0, 80 ), $color );
		}
		$cy += $line_height;
	}
	return $cy;
}


/**
 * Whether a URL may be fetched server-side for attachment image bytes (SSRF guard).
 *
 * Default: host matches {@see home_url()} (case-insensitive). CDNs on another host can use
 * `vibe_check_trusted_attachment_image_url`.
 *
 * @param string $url            Image URL.
 * @param int    $attachment_id Attachment ID (for filters).
 * @return bool
 */
function vibe_check_trusted_attachment_image_url( $url, $attachment_id = 0 ) {
	$url = (string) $url;
	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		return false;
	}
	$p_url     = wp_parse_url( $url );
	$p_home    = wp_parse_url( home_url( '/' ) );
	$url_host  = isset( $p_url['host'] ) ? strtolower( (string) $p_url['host'] ) : '';
	$home_host = isset( $p_home['host'] ) ? strtolower( (string) $p_home['host'] ) : '';
	$same      = '' !== $url_host && '' !== $home_host && $url_host === $home_host;
	/**
	 * Whether server-side fetching of this attachment image URL is allowed.
	 *
	 * @param bool   $trusted        Default: same host as home URL.
	 * @param string $url            Request URL.
	 * @param int    $attachment_id Attachment post ID.
	 */
	return (bool) apply_filters( 'vibe_check_trusted_attachment_image_url', $same, $url, (int) $attachment_id );
}

/**
 * Load a GD image from a local attachment file (JPEG/PNG/WebP/GIF).
 *
 * @param int $attachment_id Attachment ID.
 * @return resource|\GdImage|false
 */
function vibe_check_gd_load_attachment_image( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
		return false;
	}
	$path = get_attached_file( $attachment_id );
	if ( is_string( $path ) && '' !== $path && is_readable( $path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file from media library.
		$data = file_get_contents( $path );
		if ( false !== $data && '' !== $data && strlen( $data ) <= 25 * 1024 * 1024 ) {
			$im = @imagecreatefromstring( $data );
			if ( false !== $im ) {
				return $im;
			}
		}
	}
	// Fallback when the file is missing locally (e.g. offloaded media): load from the attachment URL.
	$url = wp_get_attachment_image_url( $attachment_id, 'full' );
	if ( ! is_string( $url ) || '' === $url || ! wp_http_validate_url( $url ) ) {
		return false;
	}
	if ( ! vibe_check_trusted_attachment_image_url( $url, $attachment_id ) ) {
		return false;
	}
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$tmp = download_url( $url, 30 );
	if ( is_wp_error( $tmp ) || ! is_string( $tmp ) || '' === $tmp ) {
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- temp file from download_url.
	$data = file_get_contents( $tmp );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file cleanup.
	@unlink( $tmp );
	if ( false === $data || '' === $data || strlen( $data ) > 25 * 1024 * 1024 ) {
		return false;
	}
	$im = @imagecreatefromstring( $data );
	if ( false === $im ) {
		return false;
	}
	return $im;
}

/**
 * Draw attachment image into OG card (cover crop).
 *
 * @param resource|\GdImage $dest Destination image.
 * @param resource|\GdImage $src  Source image.
 * @param int               $dx   Destination X.
 * @param int               $dy   Destination Y.
 * @param int               $dw   Destination width.
 * @param int               $dh   Destination height.
 * @return void
 */
function vibe_check_gd_image_copy_cover( $dest, $src, $dx, $dy, $dw, $dh ) {
	$sw = imagesx( $src );
	$sh = imagesy( $src );
	if ( $sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0 ) {
		return;
	}
	$dst_ratio = $dw / $dh;
	$src_ratio = $sw / $sh;
	if ( $src_ratio > $dst_ratio ) {
		$new_sh = $sh;
		$new_sw = (int) round( $sh * $dst_ratio );
		$sx     = (int) ( ( $sw - $new_sw ) / 2 );
		$sy     = 0;
	} else {
		$new_sw = $sw;
		$new_sh = (int) round( $sw / $dst_ratio );
		$sx     = 0;
		$sy     = (int) ( ( $sh - $new_sh ) / 2 );
	}
	imagecopyresampled( $dest, $src, $dx, $dy, $sx, $sy, $dw, $dh, $new_sw, $new_sh );
}

/**
 * REST callback: JPEG 1200x630.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_Error|WP_REST_Response
 */
function vibe_check_og_image_handler( WP_REST_Request $request ) {
	if ( ! vibe_check_og_rate_limit_ok() ) {
		return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
	}

	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return new WP_Error( 'gd_missing', 'GD not available', array( 'status' => 500 ) );
	}

	$post_id   = (int) $request->get_param( 'post_id' );
	$result_id = sanitize_key( (string) $request->get_param( 'result_id' ) );

	if ( $post_id <= 0 || $post_id > 2147483647 || '' === $result_id ) {
		return new WP_Error( 'bad_request', 'Invalid parameters', array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
	}

	if ( ! is_post_publicly_viewable( $post ) ) {
		return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
	}

	$sanitized = vibe_check_get_sanitized_quiz_from_post( $post );
	if ( null === $sanitized ) {
		return new WP_Error( 'not_found', 'Quiz not found', array( 'status' => 404 ) );
	}

	$results    = $sanitized['results'];
	$quiz_title = $sanitized['title'];

	$result = null;
	foreach ( $results as $r ) {
		if ( isset( $r['id'] ) && (string) $r['id'] === $result_id ) {
			$result = $r;
			break;
		}
	}
	if ( ! $result ) {
		return new WP_Error( 'not_found', 'Result not found', array( 'status' => 404 ) );
	}

	$content_hash = md5( (string) $post->post_content );
	$cache_gen    = (int) get_post_meta( $post_id, '_vibe_check_og_jpeg_gen', true );
	$cache_suffix = md5( (string) $post_id . '|' . $content_hash . '|' . $result_id . '|' . $cache_gen );
	$jpeg_key     = 'vibe_check_ogjpeg_' . $cache_suffix;
	$cached_jpeg  = get_transient( $jpeg_key );
	if ( is_string( $cached_jpeg ) && strlen( $cached_jpeg ) > 100 ) {
		return new WP_REST_Response(
			$cached_jpeg,
			200,
			array(
				'Content-Type'     => 'image/jpeg',
				'Cache-Control'    => 'public, max-age=31536000',
				'Content-Length'   => (string) strlen( $cached_jpeg ),
				'X-Content-Type-Options' => 'nosniff',
			)
		);
	}

	$title       = vibe_check_sanitize_text_for_gd( $result['title'] ?? '', 500 );
	$description = vibe_check_sanitize_text_for_gd( $result['description'] ?? '', 2000 );

	$w = 1200;
	$h = 630;

	$img = imagecreatetruecolor( $w, $h );
	if ( false === $img ) {
		return new WP_Error( 'image', 'Could not create image', array( 'status' => 500 ) );
	}

	// Neo-brutalist palette (Tier4.club–aligned: paper, ink, yellow chip, mint accent).
	$cream      = imagecolorallocate( $img, 250, 248, 244 );
	$ink        = imagecolorallocate( $img, 26, 26, 26 );
	$shadow_rgb = imagecolorallocate( $img, 26, 26, 26 );
	$chip       = imagecolorallocate( $img, 255, 240, 163 );
	$muted      = imagecolorallocate( $img, 107, 107, 107 );
	imagefill( $img, 0, 0, $cream );

	$font_dir  = VIBE_CHECK_PLUGIN_DIR . 'assets/fonts/';
	$font_bold = $font_dir . 'Inter-Bold.ttf';
	$font_reg  = $font_dir . 'Inter-Regular.ttf';

	// Hard-offset "card" shadow + frame.
	$card_x1 = 72;
	$card_y1 = 48;
	$card_x2 = $w - 72;
	$card_y2 = $h - 48;
	$off     = 8;
	imagefilledrectangle( $img, $card_x1 + $off, $card_y1 + $off, $card_x2 + $off, $card_y2 + $off, $shadow_rgb );
	imagefilledrectangle( $img, $card_x1, $card_y1, $card_x2, $card_y2, $cream );
	imagesetthickness( $img, 4 );
	imagerectangle( $img, $card_x1, $card_y1, $card_x2, $card_y2, $ink );
	imagesetthickness( $img, 1 );

	// Badge: yellow chip + border.
	$badge_w = 220;
	$bx1     = (int) ( ( $w - $badge_w ) / 2 );
	$bx2     = $bx1 + $badge_w;
	$by1     = 88;
	$by2     = 128;
	imagefilledrectangle( $img, $bx1, $by1, $bx2, $by2, $chip );
	imagerectangle( $img, $bx1, $by1, $bx2, $by2, $ink );
	if ( is_readable( $font_bold ) ) {
		imagettftext( $img, 14, 0, $bx1 + 42, 118, $ink, $font_bold, 'YOUR RESULT' );
	} else {
		imagestring( $img, 3, $bx1 + 48, 100, 'YOUR RESULT', $ink );
	}

	$photo_id = isset( $result['imageId'] ) ? absint( $result['imageId'] ) : 0;
	$src_im   = null;
	if ( $photo_id > 0 ) {
		$src_im = vibe_check_gd_load_attachment_image( $photo_id );
	}

	$photo_w = 300;
	$photo_h = 420;
	$photo_x = $card_x1 + 56;
	$photo_y = 140;
	$text_x  = $photo_x + $photo_w + 40;
	$has_photo = $src_im && ( is_resource( $src_im ) || ( is_object( $src_im ) && $src_im instanceof GdImage ) );

	if ( $has_photo ) {
		imagerectangle( $img, $photo_x - 2, $photo_y - 2, $photo_x + $photo_w + 2, $photo_y + $photo_h + 2, $ink );
		vibe_check_gd_image_copy_cover( $img, $src_im, $photo_x, $photo_y, $photo_w, $photo_h );
		imagedestroy( $src_im );
		$src_im = null;
	}

	$title_size = $has_photo ? 34 : 42;
	if ( $has_photo ) {
		$title_x = $text_x;
		if ( is_readable( $font_bold ) ) {
			imagettftext( $img, $title_size, 0, $title_x, 220, $ink, $font_bold, $title );
		} else {
			imagestring( $img, 5, max( 20, $title_x ), 200, substr( $title, 0, 40 ), $ink );
		}
		vibe_check_draw_wrapped_text( $img, $font_reg, 18, $muted, $description, $text_x, 280, 42, 28 );
	} else {
		$title_x = vibe_check_center_text_x( $title, $font_bold, 42, $w );
		if ( is_readable( $font_bold ) ) {
			imagettftext( $img, 42, 0, $title_x, 220, $ink, $font_bold, $title );
		} else {
			imagestring( $img, 5, max( 20, $title_x ), 200, substr( $title, 0, 40 ), $ink );
		}
		vibe_check_draw_wrapped_text( $img, $font_reg, 20, $muted, $description, 150, 280, 55, 32 );
	}

	$quiz_line = vibe_check_sanitize_text_for_gd( $quiz_title, 300 );
	$qx        = vibe_check_center_text_x( $quiz_line, $font_bold, 16, $w );
	if ( is_readable( $font_bold ) ) {
		imagettftext( $img, 16, 0, $qx, (int) ( $card_y2 - 36 ), $ink, $font_bold, $quiz_line );
	} else {
		imagestring( $img, 3, max( 20, $qx ), (int) ( $card_y2 - 48 ), substr( $quiz_line, 0, 60 ), $ink );
	}

	ob_start();
	imagejpeg( $img, null, 90 );
	$jpeg = ob_get_clean();
	if ( is_resource( $img ) || ( is_object( $img ) && $img instanceof GdImage ) ) {
		imagedestroy( $img );
	}

	if ( ! is_string( $jpeg ) || strlen( $jpeg ) < 100 ) {
		return new WP_Error( 'image', 'Could not encode image', array( 'status' => 500 ) );
	}

	$max_cache_bytes = (int) apply_filters( 'vibe_check_og_jpeg_cache_max_bytes', 900000 );
	if ( strlen( $jpeg ) <= $max_cache_bytes && $max_cache_bytes > 0 ) {
		$ttl = (int) apply_filters( 'vibe_check_og_jpeg_cache_ttl', DAY_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient( $jpeg_key, $jpeg, $ttl );
		}
	}

	return new WP_REST_Response(
		$jpeg,
		200,
		array(
			'Content-Type'           => 'image/jpeg',
			'Cache-Control'          => 'public, max-age=31536000',
			'Content-Length'         => (string) strlen( $jpeg ),
			'X-Content-Type-Options' => 'nosniff',
		)
	);
}

/**
 * Serve raw JPEG from REST (avoid JSON encoding of binary body).
 *
 * @param bool             $served  Whether the request was served.
 * @param WP_HTTP_Response $result  Response.
 * @param WP_REST_Request  $request Request.
 * @param WP_REST_Server   $server  Server.
 * @return bool
 */
function vibe_check_rest_pre_serve_og_image( $served, $result, $request, $server ) {
	if ( $served || is_wp_error( $result ) || ! $result instanceof WP_REST_Response ) {
		return $served;
	}
	if ( '/vibe-check/v1/og-image' !== $request->get_route() ) {
		return $served;
	}
	$data = $result->get_data();
	if ( ! is_string( $data ) || strlen( $data ) < 100 ) {
		return $served;
	}
	header( 'Content-Type: image/jpeg' );
	header( 'Cache-Control: public, max-age=31536000' );
	if ( ! headers_sent() ) {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $data ) );
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary JPEG.
	echo $data;
	return true;
}
add_filter( 'rest_pre_serve_request', 'vibe_check_rest_pre_serve_og_image', 10, 4 );

/**
 * Register REST route.
 */
function vibe_check_register_og_route() {
	register_rest_route(
		'vibe-check/v1',
		'/og-image',
		array(
			'methods'             => 'GET',
			'callback'            => 'vibe_check_og_image_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id'   => array(
					'required'            => true,
					'type'                => 'integer',
					'validate_callback'   => static function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
					'sanitize_callback'   => 'absint',
				),
				'result_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static function ( $param ) {
						return is_string( $param ) && '' !== $param && strlen( $param ) <= 64;
					},
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'vibe_check_register_og_route' );

/**
 * Output OG / Twitter meta when ?quiz_result= is present on singular posts.
 */
function vibe_check_output_result_og_tags() {
	if ( ! is_singular() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only query arg for share previews.
	if ( ! isset( $_GET['quiz_result'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$result_id = sanitize_key( wp_unslash( $_GET['quiz_result'] ) );
	if ( '' === $result_id ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$ctx = vibe_check_get_quiz_result_context( $post_id, $result_id );
	if ( null === $ctx ) {
		return;
	}

	$permalink = get_permalink( $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		$permalink = home_url( '/' );
	}
	$share_url = add_query_arg( 'quiz_result', $result_id, $permalink );

	$quiz_title   = isset( $ctx['quiz_title'] ) ? (string) $ctx['quiz_title'] : '';
	$res          = isset( $ctx['result'] ) && is_array( $ctx['result'] ) ? $ctx['result'] : array();
	$result_title = isset( $res['title'] ) ? (string) $res['title'] : '';
	$desc_raw     = isset( $res['description'] ) ? wp_strip_all_tags( (string) $res['description'] ) : '';
	$og_title     = $result_title && $quiz_title
		? sprintf(
			/* translators: 1: quiz result title, 2: quiz name */
			__( '%1$s · %2$s', 'vibe-check' ),
			$result_title,
			$quiz_title
		)
		: ( $result_title ? $result_title : $quiz_title );
	$og_title = vibe_check_truncate_utf8( $og_title, 200 );

	$og_desc = $desc_raw;
	if ( '' === $og_desc && $result_title && $quiz_title ) {
		$og_desc = sprintf(
			/* translators: 1: result title, 2: quiz title */
			__( 'My result on %2$s: %1$s', 'vibe-check' ),
			$result_title,
			$quiz_title
		);
	}

	$cta = __( 'Take the quiz to see your result.', 'vibe-check' );
	$og_desc = trim( (string) $og_desc );
	if ( '' !== $og_desc ) {
		$og_desc = $og_desc . ' ' . $cta;
	} else {
		$og_desc = $cta;
	}

	// Allow full control of OG/Twitter description (after CTA append). Args: $og_desc, $post_id, $result_id, $ctx.
	$og_desc = (string) apply_filters( 'vibe_check_og_result_description', $og_desc, $post_id, $result_id, $ctx );
	$og_desc = vibe_check_truncate_utf8( $og_desc, 300 );

	$url = rest_url( 'vibe-check/v1/og-image' );
	$url = add_query_arg(
		array(
			'post_id'   => $post_id,
			'result_id' => $result_id,
		),
		$url
	);

	echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '" />' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $share_url ) . '" />' . "\n";
	echo '<meta property="og:type" content="article" />' . "\n";

	echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $og_desc ) . '" />' . "\n";

	echo '<meta property="og:image" content="' . esc_url( $url ) . '" />' . "\n";
	echo '<meta property="og:image:width" content="1200" />' . "\n";
	echo '<meta property="og:image:height" content="630" />' . "\n";
	echo '<meta name="twitter:image" content="' . esc_url( $url ) . '" />' . "\n";
}
add_action( 'wp_head', 'vibe_check_output_result_og_tags', 5 );

/**
 * Attachment ID from Settings → default share image, or 0 if unset / invalid.
 *
 * @return int
 */
function vibe_check_get_default_share_image_attachment_id() {
	if ( ! defined( 'VIBE_CHECK_OPTION_DEFAULT_SHARE_IMAGE' ) ) {
		return 0;
	}
	$id = (int) get_option( VIBE_CHECK_OPTION_DEFAULT_SHARE_IMAGE, 0 );
	/** This filter is documented in readme.txt (vibe_check_default_share_image_attachment_id). */
	$id = (int) apply_filters( 'vibe_check_default_share_image_attachment_id', $id );
	if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
		return 0;
	}
	return $id;
}

/**
 * Full-size URL for the default share image (Settings), or empty string.
 *
 * @return string
 */
function vibe_check_get_default_share_image_url() {
	$id = vibe_check_get_default_share_image_attachment_id();
	if ( $id <= 0 ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, 'full' );
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}
	$context_id = is_singular() ? (int) get_queried_object_id() : 0;
	/** This filter is documented in readme.txt (vibe_check_default_share_image_url). */
	return (string) apply_filters( 'vibe_check_default_share_image_url', $url, $id, $context_id );
}

/**
 * Open Graph / Twitter image for quiz pages when the URL has no ?quiz_result= (landing / intro).
 */
function vibe_check_output_quiz_default_og_tags() {
	if ( ! is_singular() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['quiz_result'] ) ) {
		return;
	}

	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	if ( false === strpos( $post->post_content, 'wp:vibe-check/quiz' ) ) {
		return;
	}

	$id = vibe_check_get_default_share_image_attachment_id();
	if ( $id <= 0 ) {
		return;
	}

	$url = vibe_check_get_default_share_image_url();
	if ( '' === $url ) {
		return;
	}

	$meta = wp_get_attachment_metadata( $id );
	$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 1200;
	$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 630;
	if ( $w < 1 ) {
		$w = 1200;
	}
	if ( $h < 1 ) {
		$h = 630;
	}

	$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
	$alt = is_string( $alt ) ? vibe_check_truncate_utf8( trim( $alt ), 200 ) : '';

	echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
	echo '<meta property="og:image" content="' . esc_url( $url ) . '" />' . "\n";
	echo '<meta property="og:image:width" content="' . esc_attr( (string) $w ) . '" />' . "\n";
	echo '<meta property="og:image:height" content="' . esc_attr( (string) $h ) . '" />' . "\n";
	echo '<meta name="twitter:image" content="' . esc_url( $url ) . '" />' . "\n";
	if ( '' !== $alt ) {
		echo '<meta property="og:image:alt" content="' . esc_attr( $alt ) . '" />' . "\n";
	}
}
add_action( 'wp_head', 'vibe_check_output_quiz_default_og_tags', 4 );
