<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use WP_Error;

/**
 * Uploads an image into the media library from a remote URL or a local file
 * path on the server. Base64 is intentionally unsupported — passing raw image
 * bytes through a tool call would balloon token usage.
 *
 * The upload runs through media_handle_sideload(), which fires `add_attachment`
 * and therefore triggers MediaAnalyzer's automatic SEO/alt/embedding pass in
 * the background. Callers get the attachment ID back immediately.
 */
final class UploadMedia
{
    public const ID = 'ai-by-roadmap/upload-media';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, false),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Upload an image to the media library', 'ai-by-roadmap'),
            'description'         => __('Upload an image into the WordPress media library from a remote URL or a local file path on the server, and return the new attachment ID. SEO metadata and alt text are generated automatically in the background. Provide exactly one of "url" or "path". Base64 is not supported.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => [
                    'url'      => [
                        'type'        => 'string',
                        'description' => 'Remote URL to download the image from. The server fetches it; the bytes never pass through the tool call.',
                    ],
                    'path'     => [
                        'type'        => 'string',
                        'description' => 'Absolute path to an image file already on the server (e.g. one the agent wrote to disk). The original file is left untouched.',
                    ],
                    'filename' => [
                        'type'        => 'string',
                        'description' => 'Optional filename for the attachment (e.g. "team-photo.jpg"). Defaults to the source filename.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['attachment_id', 'url'],
                'properties'           => [
                    'attachment_id' => ['type' => 'integer'],
                    'url'           => ['type' => 'string'],
                    'filename'      => ['type' => 'string'],
                    'note'          => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('upload_files'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function execute(array $input)
    {
        $url  = isset($input['url']) ? trim((string) $input['url']) : '';
        $path = isset($input['path']) ? trim((string) $input['path']) : '';

        if (($url === '') === ($path === '')) {
            return new WP_Error(
                'invalid_source',
                __('Provide exactly one of "url" or "path".', 'ai-by-roadmap'),
                ['status' => 400]
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Resolve the source into a temp file that media_handle_sideload() can
        // consume (it renames/moves tmp_name, so we never hand it an original).
        if ($url !== '') {
            $tmp           = download_url($url);
            $source_name   = basename((string) wp_parse_url($url, PHP_URL_PATH));
        } else {
            $tmp         = self::copy_to_temp($path);
            $source_name = basename($path);
        }

        if (is_wp_error($tmp)) {
            return $tmp;
        }
        if (! is_string($tmp) || ! file_exists($tmp)) {
            return new WP_Error('source_unavailable', __('Could not read the source image.', 'ai-by-roadmap'), ['status' => 400]);
        }

        // Reject anything that is not actually an image before it lands in the library.
        if (@getimagesize($tmp) === false) {
            @unlink($tmp);
            return new WP_Error('not_an_image', __('The provided file is not a valid image.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $filename = isset($input['filename']) ? trim((string) $input['filename']) : '';
        if ($filename === '') {
            $filename = $source_name !== '' ? $source_name : 'image-' . gmdate('YmdHis');
        }

        $attachment_id = media_handle_sideload(
            ['name' => sanitize_file_name($filename), 'tmp_name' => $tmp],
            0
        );

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        $attachment_id = (int) $attachment_id;

        return [
            'attachment_id' => $attachment_id,
            'url'           => (string) wp_get_attachment_url($attachment_id),
            'filename'      => (string) get_post_field('post_title', $attachment_id),
            'note'          => __('SEO title, alt text, and description are being generated automatically in the background.', 'ai-by-roadmap'),
        ];
    }

    /**
     * Copy a local source file to a WordPress temp file so the sideload can
     * consume it without disturbing the caller's original.
     *
     * @return string|WP_Error
     */
    private static function copy_to_temp(string $path)
    {
        if ($path === '' || ! @is_file($path) || ! @is_readable($path)) {
            return new WP_Error('path_not_found', __('The file path does not exist or is not readable.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $tmp = wp_tempnam(basename($path));
        if (! $tmp) {
            return new WP_Error('temp_failed', __('Could not create a temporary file.', 'ai-by-roadmap'), ['status' => 500]);
        }

        if (! @copy($path, $tmp)) {
            @unlink($tmp);
            return new WP_Error('copy_failed', __('Could not copy the source file.', 'ai-by-roadmap'), ['status' => 500]);
        }

        return $tmp;
    }
}
