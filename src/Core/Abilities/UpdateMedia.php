<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use WP_Error;

/**
 * Edit an attachment's alt text, title, caption and description. Agents
 * upload images but could not fix their accessibility text afterwards.
 */
final class UpdateMedia
{
    public const ID = 'ai-by-roadmap/update-media';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Update media alt text / title / caption', 'ai-by-roadmap'),
            'description'         => __('Set an attachment\'s alt text, title, caption and/or description. Supply only the fields to change. Alt text is what screen readers and SEO use — write what the image shows (or "" for purely decorative images). Get attachment IDs from search-media, upload-media or get-post-blocks image_fields.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['attachment_id'],
                'properties'           => [
                    'attachment_id' => ['type' => 'integer'],
                    'alt'           => ['type' => 'string', 'description' => 'Alternative text (plain text).'],
                    'title'         => ['type' => 'string'],
                    'caption'       => ['type' => 'string'],
                    'description'   => ['type' => 'string'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'attachment_id', 'changed_fields', 'alt', 'title', 'caption', 'description', 'url'],
                'properties'           => [
                    'success'        => ['type' => 'boolean'],
                    'attachment_id'  => ['type' => 'integer'],
                    'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'alt'            => ['type' => 'string'],
                    'title'          => ['type' => 'string'],
                    'caption'        => ['type' => 'string'],
                    'description'    => ['type' => 'string'],
                    'url'            => ['type' => 'string'],
                    'mime_type'      => ['type' => 'string'],
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
        $id   = (int) $input['attachment_id'];
        $post = get_post($id);
        if (! $post || $post->post_type !== 'attachment') {
            return new WP_Error('attachment_not_found', __('Attachment not found.', 'ai-by-roadmap'), ['status' => 404]);
        }
        if (! current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', __('You are not allowed to edit this attachment.', 'ai-by-roadmap'), ['status' => 403]);
        }
        if (! array_intersect_key($input, ['alt' => 1, 'title' => 1, 'caption' => 1, 'description' => 1])) {
            return new WP_Error('nothing_to_update', __('Supply at least one of alt, title, caption or description.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $changed = [];
        $update  = ['ID' => $id];

        if (array_key_exists('alt', $input)) {
            $alt = sanitize_text_field((string) $input['alt']);
            if ($alt !== (string) get_post_meta($id, '_wp_attachment_image_alt', true)) {
                update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
                $changed[] = 'alt';
            }
        }
        if (array_key_exists('title', $input) && (string) $input['title'] !== (string) $post->post_title) {
            $update['post_title'] = wp_slash((string) $input['title']);
            $changed[]            = 'title';
        }
        if (array_key_exists('caption', $input) && (string) $input['caption'] !== (string) $post->post_excerpt) {
            $update['post_excerpt'] = wp_slash((string) $input['caption']);
            $changed[]              = 'caption';
        }
        if (array_key_exists('description', $input) && (string) $input['description'] !== (string) $post->post_content) {
            $update['post_content'] = wp_slash((string) $input['description']);
            $changed[]              = 'description';
        }

        if (count($update) > 1) {
            $result = wp_update_post($update, true);
            if (is_wp_error($result)) {
                return $result;
            }
            clean_post_cache($id);
            $post = get_post($id) ?: $post;
        }

        return [
            'success'        => true,
            'attachment_id'  => $id,
            'changed_fields' => $changed,
            'alt'            => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'title'          => (string) $post->post_title,
            'caption'        => (string) $post->post_excerpt,
            'description'    => (string) $post->post_content,
            'url'            => (string) wp_get_attachment_url($id),
            'mime_type'      => (string) $post->post_mime_type,
        ];
    }
}
