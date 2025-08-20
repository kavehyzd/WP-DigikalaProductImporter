<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * اجازه آپلود webp (اگر روی لوکال یا ورژن پایین‌تر محدودیت داشت)
 */
add_filter('upload_mimes', function($mimes){
    $mimes['webp'] = 'image/webp';
    return $mimes;
});

/**
 * تشخیص پسوند از Content-Type
 */
function digikala_ext_from_content_type( $content_type ) {
    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    return $map[ strtolower($content_type) ] ?? 'jpg';
}

/**
 * دانلود امن فایل به مسیر موقت با WP HTTP API و User-Agent
 * خروجی: ['tmp_path' => '...', 'filename' => '...', 'type' => 'image/...'] یا WP_Error
 */
function digikala_http_download_to_temp( $url, $suggested_name = null ) {
    $response = wp_remote_get( $url, [
        'timeout' => 60,
        'sslverify' => true,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) WP-Digikala-Importer',
            'Accept'     => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ],
    ] );

    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'bad_status', 'HTTP Status: ' . $code );
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        return new WP_Error( 'empty_body', 'Body is empty' );
    }

    $type = wp_remote_retrieve_header( $response, 'content-type' );
    $ext  = digikala_ext_from_content_type( $type );

    // نام فایل کوتاه و تمیز
    if ( ! $suggested_name ) {
        $parsed = wp_parse_url( $url );
        $base   = isset($parsed['path']) ? basename($parsed['path']) : 'image';
        $base   = preg_replace('/[^A-Za-z0-9\-]+/', '-', pathinfo($base, PATHINFO_FILENAME));
        $suggested_name = $base ?: 'image';
    }
    $filename = sanitize_file_name( $suggested_name . '.' . $ext );

    // نوشتن در فایل موقت
    $tmp = wp_tempnam( $filename );
    if ( ! $tmp ) return new WP_Error( 'tmp_failed', 'Cannot create temp file' );

    $written = file_put_contents( $tmp, $body );
    if ( ! $written ) {
        @unlink( $tmp );
        return new WP_Error( 'write_failed', 'Cannot write to temp file' );
    }

    return [
        'tmp_path' => $tmp,
        'filename' => $filename,
        'type'     => $type ?: 'image/' . $ext,
    ];
}

/**
 * الحاق تصویر به رسانه و نسبت‌دادن به پست
 * خروجی: attachment_id یا false
 */
function digikala_attach_temp_image( $temp, $post_id ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $file_array = [
        'name'     => $temp['filename'],
        'tmp_name' => $temp['tmp_path'],
        'type'     => $temp['type'],
    ];

    // از media_handle_sideload استفاده می‌کنیم
    $attach_id = media_handle_sideload( $file_array, $post_id );

    if ( is_wp_error( $attach_id ) ) {
        @unlink( $file_array['tmp_name'] );
        return false;
    }
    return $attach_id;
}

/**
 * دانلود و الصاق مستقیم تصویر از URL
 * $filename_prefix مثلا: dk-15572856-main یا dk-15572856-g1
 */
function digikala_download_image( $url, $post_id, $filename_prefix = 'dk-img' ) {
    $temp = digikala_http_download_to_temp( $url, $filename_prefix );
    if ( is_wp_error( $temp ) ) {
        error_log( '[Digikala Importer] download failed: ' . $url . ' => ' . $temp->get_error_message() );
        return false;
    }
    return digikala_attach_temp_image( $temp, $post_id );
}

/**
 * جست‌وجوی بازگشتی URL تصویر در هر ساختار آرایه/آبجکت
 */
function digikala_find_image_urls_recursive( $node, &$found, $limit ) {
    if ( count($found) >= $limit ) return;

    if ( is_array($node) ) {
        foreach ( $node as $k => $v ) {
            if ( count($found) >= $limit ) break;

            // اگر مقدار رشته‌ای URL بود
            if ( is_string($v) && preg_match('#^https?://#i', $v) ) {
                // فقط URLهای مربوط به تصویر
                if ( preg_match('#\.(jpg|jpeg|png|webp|gif)(\?|$)#i', $v) || str_contains($v, 'dkstatics') ) {
                    $found[] = $v;
                    continue;
                }
            }
            // اگر کلیدهای معروف URL وجود داشت
            if ( is_array($v) || is_object($v) ) {
                digikala_find_image_urls_recursive( $v, $found, $limit );
            }
        }
    } elseif ( is_object($node) ) {
        foreach ( get_object_vars($node) as $k => $v ) {
            if ( count($found) >= $limit ) break;

            if ( is_string($v) && preg_match('#^https?://#i', $v) ) {
                if ( preg_match('#\.(jpg|jpeg|png|webp|gif)(\?|$)#i', $v) || str_contains($v, 'dkstatics') ) {
                    $found[] = $v;
                    continue;
                }
            }
            if ( is_array($v) || is_object($v) ) {
                digikala_find_image_urls_recursive( $v, $found, $limit );
            }
        }
    }
}

/**
 * استخراج URLهای تصویر از ساختار images محصول دیجی‌کالا
 * خروجی: ['main' => '...', 'gallery' => ['...','...']]
 */
function digikala_extract_images( $product_data, $gallery_limit = 3 ) {
    $result = [ 'main' => null, 'gallery' => [] ];

    // شاخه‌های احتمالی
    $branches_main = [
        $product_data['images']['main'] ?? null,
        $product_data['images']['image'] ?? null,
    ];
    $branches_gallery = [
        $product_data['images']['list'] ?? null,
        $product_data['images']['gallery'] ?? null,
        $product_data['images'] ?? null,
    ];

    // main
    foreach ( $branches_main as $b ) {
        if ( empty($b) ) continue;
        $tmp = [];
        digikala_find_image_urls_recursive( $b, $tmp, 1 );
        if ( ! empty($tmp) ) { $result['main'] = $tmp[0]; break; }
    }

    // gallery
    $gathered = [];
    foreach ( $branches_gallery as $b ) {
        if ( empty($b) ) continue;
        digikala_find_image_urls_recursive( $b, $gathered, $gallery_limit + 5 ); // کمی اضافه برای فیلتر
        if ( count($gathered) >= $gallery_limit + 1 ) break;
    }

    // حذف تکراری‌ها و حذف main از گالری
    $gathered = array_values( array_unique( $gathered ) );
    if ( $result['main'] ) {
        $gathered = array_values( array_filter( $gathered, fn($u) => $u !== $result['main'] ) );
    }

    $result['gallery'] = array_slice( $gathered, 0, max(0, (int)$gallery_limit) );
    return $result;
}
