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

require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Ambil konfigurasi AWS dari environment variable atau constant
 */
function get_aws_settings()
{
    return [
        'access_key' => getenv('AWS_ACCESS_KEY_ID') ?: (defined('AWS_ACCESS_KEY_ID') ? AWS_ACCESS_KEY_ID : ''),
        'secret_key' => getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('AWS_SECRET_ACCESS_KEY') ? AWS_SECRET_ACCESS_KEY : ''),
        'bucket'     => 'bucket-pbdnews',
        'region'     => 'ap-southeast-1',
    ];
}

/**
 * Generate Signed URL untuk file di Amazon S3
 *
 * @param string $file_path Path file dalam bucket S3
 * @return string Signed URL atau pesan error
 */
function generate_signed_url($file_path)
{
    $settings = get_aws_settings();

    if (empty($settings['access_key']) || empty($settings['secret_key'])) {
        return 'Error: AWS credentials not set.';
    }

    try {
        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => $settings['region'],
            'credentials' => [
                'key'    => $settings['access_key'],
                'secret' => $settings['secret_key']
            ]
        ]);

        $cmd = $s3->getCommand('GetObject', [
            'Bucket' => $settings['bucket'],
            'Key'    => $file_path
        ]);

        $request = $s3->createPresignedRequest($cmd, '+1 hour');
        return (string) $request->getUri();
    } catch (AwsException $e) {
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Ganti URL gambar di konten WordPress dengan signed URL dari S3
 *
 * @param string $content HTML konten yang akan diproses
 * @return string Konten dengan URL gambar yang diperbarui
 */
function replace_image_urls_with_signed($content)
{
    $cdn_domain = 'https://assets.pbdnews.com';

    if (empty($cdn_domain)) {
        return $content;
    }

    // Regex untuk menangkap URL gambar yang berada di wp-content/uploads/
    $pattern = '/(https?:\/\/' . preg_quote(parse_url($cdn_domain, PHP_URL_HOST), '/') . '\/wp-content\/uploads\/[^\s"\']+)/i';

    return preg_replace_callback($pattern, function ($matches) {
        $original_url = $matches[0];

        // Ambil path relatif dari URL
        $file_path = str_replace('https://assets.pbdnews.com/', '', $original_url);

        // Buat signed URL dari S3
        $signed_url = generate_signed_url($file_path);

        return $signed_url ?: $original_url;
    }, $content);
}

// Terapkan filter untuk mengganti URL di konten WordPress
add_filter('the_content', 'replace_image_urls_with_signed');
add_filter('post_thumbnail_html', 'replace_image_urls_with_signed');
add_filter('widget_text', 'replace_image_urls_with_signed');
add_filter('get_avatar', 'replace_image_urls_with_signed');
