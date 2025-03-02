<?php

/**
 * Plugin Name: S3 Signed URL Replacer
 * Description: Mengganti URL gambar di /wp-content/uploads/ dengan signed URL dari Amazon S3.
 * Version: 1.1
 * Author: Thoriq_Hrz
 */

if (!defined('ABSPATH')) {
    exit; // Mencegah akses langsung ke file
}

// Pastikan AWS SDK tersedia
require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function get_as3cf_settings()
{
    $settings = defined('AS3CF_SETTINGS') ? unserialize(AS3CF_SETTINGS) : [];

    return [
        'provider'      => $settings['provider'] ?? '',
        'access_key'    => $settings['access-key-id'] ?? '',
        'secret_key'    => $settings['secret-access-key'] ?? '',
    ];
}

/**
 * Generate Signed URL for Amazon S3
 *
 * @param string $file_path Path file dalam bucket S3
 * @return string Signed URL atau URL asli jika gagal
 */
function generate_signed_url($file_path)
{
    $settings = get_as3cf_settings();

    $accessKey = $settings['access_key'];
    $secretKey = $settings['secret_key'];
    $bucket    = 'bucket-pbdnews'; // Sesuaikan dengan nama bucket Anda
    $region    = 'ap-southeast-1'; // Sesuaikan dengan region bucket
    $expires   = "+1 hour";

    if (empty($accessKey) || empty($secretKey) || empty($bucket) || empty($region)) {
        return false;
    }

    try {
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key'    => $accessKey,
                'secret' => $secretKey
            ],
            'signature_version' => 'v4', // Tambahkan signature version
        ]);

        $cmd = $s3->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key'    => ltrim($file_path, '/')
        ]);

        $request = $s3->createPresignedRequest($cmd, $expires);
        return (string) $request->getUri();
    } catch (AwsException $e) {
        error_log('S3 Signed URL Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Merge query parameters from the original URL with the signed URL
 *
 * @param string $signed_url The signed URL
 * @param string $original_url The original URL
 * @return string The merged URL
 */
function merge_query_params($signed_url, $original_url)
{
    $parsed_signed_url = parse_url($signed_url);
    $parsed_original_url = parse_url($original_url);

    $signed_query = [];
    if (!empty($parsed_signed_url['query'])) {
        parse_str($parsed_signed_url['query'], $signed_query);
    }

    $original_query = [];
    if (!empty($parsed_original_url['query'])) {
        parse_str($parsed_original_url['query'], $original_query);
    }

    $merged_query = array_merge($signed_query, $original_query);
    $query_string = http_build_query($merged_query);

    return $parsed_signed_url['scheme'] . '://' . $parsed_signed_url['host'] . $parsed_signed_url['path'] . '?' . $query_string;
}


/**
 * Check if a URL is already a valid S3 signed URL
 *
 * @param string $url The URL to check
 * @return bool True if the URL is already a signed URL, false otherwise
 */
function is_signed_url($url)
{
    // Parameter kunci yang biasanya ada di signed URL S3
    $s3_signature_params = [
        'X-Amz-Signature',
        'X-Amz-Algorithm',
        'X-Amz-Credential',
        'X-Amz-Date',
        'X-Amz-Expires',
        'X-Amz-SignedHeaders',
    ];

    $parsed_url = parse_url($url);
    if (empty($parsed_url['query'])) {
        return false; // Tidak ada query parameters, bukan signed URL
    }

    parse_str($parsed_url['query'], $query_params);

    // Periksa apakah semua parameter kunci ada di query
    foreach ($s3_signature_params as $param) {
        if (!isset($query_params[$param])) {
            return false; // Parameter kunci tidak ditemukan, bukan signed URL
        }
    }

    return true; // Semua parameter kunci ditemukan, URL sudah signed
}



/**
 * Filter URL gambar yang mengarah ke /wp-content/uploads/
 *
 * @param string $content HTML konten yang akan diproses
 * @return string Konten dengan URL gambar diganti dengan signed URL
 */
function replace_image_urls_with_signed($content)
{
    $cdn_domain = 'https://assets.pbdnews.com';
    $s3_domain  = 'https://bucket-pbdnews.s3.ap-southeast-1.amazonaws.com'; // Sesuaikan dengan domain S3 Anda

    if (empty($cdn_domain) || empty($s3_domain)) {
        return $content;
    }

    // Regex untuk menangkap URL gambar yang berasal dari S3 atau CDN
    $pattern = '/(https?:\/\/(?:' . preg_quote(parse_url($s3_domain, PHP_URL_HOST), '/') . '|' . preg_quote(parse_url($cdn_domain, PHP_URL_HOST), '/') . ')(\/wp-content\/uploads\/[^\s"\']+))/i';

    return preg_replace_callback($pattern, function ($matches) use ($s3_domain, $cdn_domain) {
        $original_url = $matches[0];

        // Jika URL sudah merupakan signed URL yang valid, biarkan saja
        if (is_signed_url($original_url)) {
            return $original_url;
        }

        // Pisahkan path file dari query parameters (jika ada)
        $url_parts = parse_url($original_url);
        $file_path = ltrim($url_parts['path'], '/'); // Ambil path tanpa domain

        // Buat signed URL dari S3
        $signed_url = generate_signed_url($file_path);

        // Jika signed URL gagal dibuat, kembalikan URL asli
        if (!$signed_url) {
            return $original_url;
        }

        // Gabungkan signed URL dengan query string asli (jika ada)
        return merge_query_params($signed_url, $original_url);
    }, $content);
}

// Terapkan filter untuk mengganti URL di berbagai bagian WordPress
add_filter('the_content', 'replace_image_urls_with_signed', 99);
add_filter('post_thumbnail_html', 'replace_image_urls_with_signed', 99);
add_filter('widget_text', 'replace_image_urls_with_signed', 99);
add_filter('get_avatar', 'replace_image_urls_with_signed', 99);
add_filter('wp_get_attachment_url', 'replace_image_urls_with_signed', 99);
add_filter('wp_get_attachment_image_src', function ($image) {
    if (!empty($image[0])) {
        $image[0] = replace_image_urls_with_signed($image[0]);
    }
    return $image;
}, 99);
