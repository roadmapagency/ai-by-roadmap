<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\ACFTransformer;
use Roadmap\AiByRoadmap\Blocks\BlockRegistry;
use Roadmap\AiByRoadmap\Plugin;

/**
 * Non-metered counterpart to compose-page. The caller (typically an external
 * LLM driving the MCP server) has already analysed the source, chosen its
 * blocks, and filled every field itself — using the schemas returned by
 * list-blocks. This ability only runs the deterministic tail of the pipeline:
 * serialize the filled blocks into ACF block markup and persist the page.
 *
 * No LLM is called here. Use compose-page instead when you have raw content and
 * no model of your own to do the analyse/choose/fill work.
 */
final class AssemblePage
{
    public const ID = 'ai-by-roadmap/assemble-page';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Assemble a page from filled blocks', 'ai-by-roadmap'),
            'description'         => __('Assemble and persist a WordPress page from blocks you have already filled yourself — no AI is called. Use this when an LLM is driving the MCP: call list-blocks to learn each block\'s field schema, fill the fields yourself, then pass the ordered list of {type, fields} here. The blocks are serialized to ACF markup and saved to a new draft page (or an existing post when post_id + replace_content are given). Prefer compose-page only when you have raw content and no model to do the analyse/choose/fill work.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['blocks'],
                'properties'           => [
                    'blocks'          => [
                        'type'        => 'array',
                        'description' => 'Ordered list of blocks to place on the page, top to bottom.',
                        'items'       => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['type', 'fields'],
                            'properties'           => [
                                'type'   => [
                                    'type'        => 'string',
                                    'description' => 'Block type ID (e.g. acf/hero). Must be one returned by list-blocks.',
                                    'enum'        => array_keys(BlockRegistry::get_blocks()),
                                ],
                                'fields' => [
                                    'type'                 => 'object',
                                    'additionalProperties' => true,
                                    'description'          => 'Field values for this block, matching its schema from list-blocks. May include an ai_content string holding the verbatim source slice used for this block.',
                                ],
                            ],
                        ],
                    ],
                    'post_id'         => [
                        'type'        => 'integer',
                        'description' => 'Optional post ID. If provided together with replace_content, that post is overwritten. If omitted, a new draft page is created.',
                    ],
                    'replace_content' => [
                        'type'        => 'boolean',
                        'description' => 'When post_id is provided, set true to overwrite its content. Ignored when post_id is omitted.',
                    ],
                    'title'           => [
                        'type'        => 'string',
                        'description' => 'Title for the new page when post_id is omitted. Defaults to "AI generated page" plus a timestamp.',
                    ],
                    'post_type'       => [
                        'type'        => 'string',
                        'description' => 'Post type for the new page when post_id is omitted. Defaults to "page".',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['blocks'],
                'properties'           => [
                    'blocks'    => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'post_id'   => ['type' => 'integer'],
                    'edit_link' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public static function execute(array $input)
    {
        $registered = BlockRegistry::get_blocks();
        $transformer = new ACFTransformer();

        $serialized = [];
        foreach ((array) $input['blocks'] as $block) {
            $block = (array) $block;
            $type  = (string) ($block['type'] ?? '');

            if (! isset($registered[$type])) {
                return new \WP_Error(
                    'invalid_block_type',
                    sprintf(
                        /* translators: 1: block type, 2: list of registered block IDs */
                        __('Block "%1$s" is not registered. Registered: %2$s.', 'ai-by-roadmap'),
                        $type,
                        implode(', ', array_keys($registered))
                    )
                );
            }

            // ai_content holds the verbatim source slice for this block; default
            // to empty so a block that legitimately has none still serializes.
            $fields = (array) ($block['fields'] ?? []);
            $fields[Plugin::AI_CONTENT_FIELD] = (string) ($fields[Plugin::AI_CONTENT_FIELD] ?? '');

            $serialized[] = $transformer->convert([$type => $fields]);
        }

        $result   = ['blocks' => $serialized];
        $content  = implode("\n\n", $serialized);
        $post_id  = isset($input['post_id']) ? (int) $input['post_id'] : null;

        if ($post_id && ! empty($input['replace_content'])) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $content,
            ]);
            $result['post_id']   = $post_id;
            $result['edit_link'] = (string) get_edit_post_link($post_id, 'raw');
        } elseif (! $post_id) {
            $title = (string) ($input['title'] ?? '');
            if ($title === '') {
                $title = sprintf(
                    /* translators: %s: current date/time */
                    __('AI generated page — %s', 'ai-by-roadmap'),
                    date_i18n('M j, Y \a\t g:ia')
                );
            }

            $post_type = (string) ($input['post_type'] ?? 'page');
            if (! post_type_exists($post_type)) {
                return new \WP_Error('invalid_post_type', sprintf(
                    /* translators: %s: post type */
                    __('Post type "%s" is not registered.', 'ai-by-roadmap'),
                    $post_type
                ));
            }

            $new_id = wp_insert_post([
                'post_type'    => $post_type,
                'post_status'  => 'draft',
                'post_title'   => $title,
                'post_content' => $content,
            ], true);

            if (is_wp_error($new_id)) {
                return $new_id;
            }

            $result['post_id']   = (int) $new_id;
            $result['edit_link'] = (string) get_edit_post_link((int) $new_id, 'raw');
        }

        return $result;
    }
}
