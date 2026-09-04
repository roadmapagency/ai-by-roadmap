<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use WP_Error;

/**
 * Sets an image field on one ACF block of an existing post, deterministically —
 * without regenerating the block's content. The block is addressed by its index
 * among the post's ACF blocks (see get-post-blocks) and the image field by name,
 * so blocks with more than one image field can be targeted precisely.
 *
 * v1 handles top-level image fields only; images nested inside repeaters or
 * groups are out of scope. Non-ACF rows (e.g. `core/block` synced patterns) are
 * skipped, do not consume an index, and survive the serialize_blocks() round-trip
 * untouched.
 */
final class SetBlockImage
{
    public const ID = 'ai-by-roadmap/set-block-image';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Set an image on a block', 'ai-by-roadmap'),
            'description'         => __('Set an image field on a specific ACF block of a post to a media-library attachment, without changing any other content. Use get-post-blocks first to find block_index and the field name. Handles top-level image fields only.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'block_index', 'field', 'attachment_id'],
                'properties'           => [
                    'post_id'       => ['type' => 'integer', 'description' => 'The post to update.'],
                    'block_index'   => ['type' => 'integer', 'description' => 'Zero-based index among the post\'s ACF blocks, as returned by get-post-blocks.'],
                    'field'         => ['type' => 'string', 'description' => 'The image field name to set (e.g. "image" or "image_circle").'],
                    'attachment_id' => ['type' => 'integer', 'description' => 'The media-library attachment ID to place in the field.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'post_id', 'block_index', 'field', 'attachment_id'],
                'properties'           => [
                    'success'       => ['type' => 'boolean'],
                    'post_id'       => ['type' => 'integer'],
                    'block_index'   => ['type' => 'integer'],
                    'field'         => ['type' => 'string'],
                    'attachment_id' => ['type' => 'integer'],
                    'image_url'     => ['type' => 'string'],
                    'edit_link'     => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function execute(array $input)
    {
        $post_id       = (int) $input['post_id'];
        $block_index   = (int) $input['block_index'];
        $field         = trim((string) $input['field']);
        $attachment_id = (int) $input['attachment_id'];

        $post = get_post($post_id);
        if (! $post) {
            return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
        }

        if (! wp_attachment_is_image($attachment_id)) {
            return new WP_Error('invalid_attachment', __('attachment_id is not an image attachment.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $blocks   = parse_blocks((string) $post->post_content);
        $acf_seen = 0;
        $target   = null;

        foreach ($blocks as $i => $block) {
            if (! str_starts_with((string) ($block['blockName'] ?? ''), 'acf/')) {
                continue;
            }
            if ($acf_seen === $block_index) {
                $target = $i;
                break;
            }
            $acf_seen++;
        }

        if ($target === null) {
            return new WP_Error('block_not_found', sprintf(
                /* translators: %d: requested block index */
                __('No ACF block at index %d on this post.', 'ai-by-roadmap'),
                $block_index
            ), ['status' => 404]);
        }

        $block_name   = (string) $blocks[$target]['blockName'];
        $image_fields = self::image_field_names($block_name);

        if (! in_array($field, $image_fields, true)) {
            return new WP_Error('invalid_field', sprintf(
                /* translators: 1: field name, 2: block type, 3: comma-separated valid field names */
                __('"%1$s" is not an image field on %2$s. Valid image fields: %3$s.', 'ai-by-roadmap'),
                $field,
                $block_name,
                $image_fields ? implode(', ', $image_fields) : __('(none)', 'ai-by-roadmap')
            ), ['status' => 400]);
        }

        // Set the value and ensure the ACF field-key reference exists, matching
        // ACFTransformer's `field_{block_slug}_{field}` convention.
        $blocks[$target]['attrs']['data'][$field] = $attachment_id;
        $ref_key = '_' . $field;
        if (empty($blocks[$target]['attrs']['data'][$ref_key])) {
            $block_slug = str_replace('acf/', '', $block_name);
            $blocks[$target]['attrs']['data'][$ref_key] = 'field_' . $block_slug . '_' . $field;
        }

        $content = serialize_blocks($blocks);

        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_slash($content),
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success'       => true,
            'post_id'       => $post_id,
            'block_index'   => $block_index,
            'field'         => $field,
            'attachment_id' => $attachment_id,
            'image_url'     => (string) wp_get_attachment_url($attachment_id),
            'edit_link'     => (string) get_edit_post_link($post_id, 'raw'),
        ];
    }

    /**
     * Top-level image field names for a block type, via ACF introspection.
     *
     * @return array<int, string>
     */
    private static function image_field_names(string $block_name): array
    {
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            return [];
        }

        $names = [];
        foreach (acf_get_field_groups(['block' => $block_name]) as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                if (($field['type'] ?? '') === 'image') {
                    $names[] = (string) $field['name'];
                }
            }
        }

        return $names;
    }
}
