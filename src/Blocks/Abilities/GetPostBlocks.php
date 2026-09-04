<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use WP_Error;

/**
 * Lists the ACF blocks on a post in document order, together with each block's
 * image fields and their current values. This is the discovery step for the
 * upload → set-block-image loop: a caller uses it to find which block index and
 * field name to target (especially on blocks with more than one image field,
 * e.g. ImageAndText's `image` + `image_circle`).
 *
 * Image fields cannot be detected from the registered JSON schema — ACF image
 * fields serialize as plain strings there — so we introspect ACF's own field
 * definitions for the block (type === 'image').
 *
 * Non-ACF rows (e.g. `core/block` synced patterns from a locked CPT template)
 * are skipped and do not consume an index; their content is edited in the
 * pattern itself.
 */
final class GetPostBlocks
{
    public const ID = 'ai-by-roadmap/get-post-blocks';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('List a post\'s blocks and image fields', 'ai-by-roadmap'),
            'description'         => __('List the ACF blocks on a post in order, each with its image fields and current values (attachment ID + URL, or empty). Use this to find the block_index and field name to pass to set-block-image.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The post ID to inspect.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['blocks'],
                'properties'           => [
                    'blocks' => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['index', 'block_type', 'image_fields'],
                            'properties'           => [
                                'index'        => ['type' => 'integer', 'description' => 'Zero-based index among the post\'s ACF blocks. Pass this as block_index to set-block-image.'],
                                'block_type'   => ['type' => 'string'],
                                'heading'      => ['type' => 'string', 'description' => 'The block\'s heading/title text, if any — a hint for disambiguating blocks.'],
                                'image_fields' => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type'                 => 'object',
                                        'additionalProperties' => false,
                                        'required'             => ['name', 'attachment_id'],
                                        'properties'           => [
                                            'name'          => ['type' => 'string'],
                                            'label'         => ['type' => 'string'],
                                            'attachment_id' => ['type' => 'integer', 'description' => '0 when the field is empty.'],
                                            'url'           => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
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
        $post_id = (int) $input['post_id'];
        $post    = get_post($post_id);

        if (! $post) {
            return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
        }

        $blocks = [];
        $index  = 0;

        foreach (parse_blocks((string) $post->post_content) as $block) {
            $name = (string) ($block['blockName'] ?? '');
            if (! str_starts_with($name, 'acf/')) {
                continue;
            }

            $data = (array) ($block['attrs']['data'] ?? []);

            $image_fields = [];
            foreach (self::image_field_names($name) as $field_name => $label) {
                $value         = $data[$field_name] ?? '';
                $attachment_id = is_numeric($value) ? (int) $value : 0;

                $image_fields[] = [
                    'name'          => $field_name,
                    'label'         => $label,
                    'attachment_id' => $attachment_id,
                    'url'           => $attachment_id > 0 ? (string) wp_get_attachment_url($attachment_id) : '',
                ];
            }

            $entry = [
                'index'        => $index,
                'block_type'   => $name,
                'image_fields' => $image_fields,
            ];

            $heading = self::heading_hint($data);
            if ($heading !== '') {
                $entry['heading'] = $heading;
            }

            $blocks[] = $entry;
            $index++;
        }

        return ['blocks' => $blocks];
    }

    /**
     * Top-level image fields for a block type, keyed by field name → label.
     * Introspects ACF directly; returns an empty list if ACF is unavailable.
     *
     * @return array<string, string>
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
                    $names[(string) $field['name']] = (string) ($field['label'] ?? $field['name']);
                }
            }
        }

        return $names;
    }

    /**
     * Best-effort heading hint from common heading/title field names.
     *
     * @param array<string, mixed> $data
     */
    private static function heading_hint(array $data): string
    {
        foreach (['heading', 'title', 'headline'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                return trim(wp_strip_all_tags($data[$key]));
            }
        }
        return '';
    }
}
