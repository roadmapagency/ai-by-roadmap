<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Plugin;
use WP_Error;

/**
 * Lists the ACF blocks on a post in document order. This is the read half of
 * every partial edit: the index it reports is what set-block-image,
 * update-block-fields, insert-block, remove-block and move-block address.
 *
 * By default each entry is small (index, type, label, image fields) so a page
 * of 16 blocks fits comfortably in an agent's context. Pass include_fields to
 * get every field value in the human shape list-blocks advertises (repeaters as
 * arrays of rows, images as attachment IDs), and block_index to fetch one block.
 *
 * Non-ACF rows (e.g. `core/block` synced patterns from a locked CPT template)
 * are skipped and do not consume an index; they are listed under fixed_rows so
 * the gap between total_blocks and acf_blocks is explicit. Their content is
 * edited in the pattern itself.
 */
final class GetPostBlocks
{
    public const ID = 'ai-by-roadmap/get-post-blocks';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('List a post\'s blocks and their fields', 'ai-by-roadmap'),
            'description'         => __('List the ACF blocks on a post in order — the discovery step before any partial edit. Each entry has index, block_type, label (its heading or other identifying text) and image_fields. Pass include_fields: true to also get every field\'s current value (fields object, human field names, repeaters as arrays of rows, images as attachment IDs) and block_index to return a single block. The index counts ACF blocks only: fixed rows such as synced patterns are skipped (see fixed_rows), which is why find-posts block_count can exceed acf_blocks. Pass the returned index as block_index to update-block-fields / set-block-image / remove-block / move-block, and the returned modified as expected_modified to guard against concurrent edits.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id'            => [
                        'type'        => 'integer',
                        'description' => 'The post ID to inspect.',
                    ],
                    'include_fields'     => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Include every field\'s current value on each block (fields object). Off by default to keep responses small.',
                    ],
                    'include_ai_content' => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'With include_fields, also include each block\'s ai_content (the verbatim source slice). Off by default — it is long and rarely needed for edits.',
                    ],
                    'block_index'        => [
                        'type'        => 'integer',
                        'description' => 'Return only the ACF block at this zero-based index (e.g. 0 for the hero). Omit to list all blocks.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'modified', 'total_blocks', 'acf_blocks', 'fixed_rows', 'blocks'],
                'properties'           => [
                    'post_id'      => ['type' => 'integer'],
                    'post_type'    => ['type' => 'string'],
                    'modified'     => ['type' => 'string', 'description' => 'Post modified timestamp. Pass as expected_modified to a write tool to refuse the write if the post changed in between.'],
                    'total_blocks' => ['type' => 'integer', 'description' => 'All named blocks incl. fixed rows — equals find-posts block_count.'],
                    'acf_blocks'   => ['type' => 'integer', 'description' => 'Indexable ACF blocks (the index space of every block_index parameter).'],
                    'fixed_rows'   => [
                        'type'        => 'array',
                        'description' => 'Non-ACF rows (e.g. synced patterns) with their document position; they do not consume an index.',
                        'items'       => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['position', 'block_type'],
                            'properties'           => [
                                'position'   => ['type' => 'integer'],
                                'block_type' => ['type' => 'string'],
                                'ref'        => ['type' => 'integer', 'description' => 'The wp_block post ID for synced patterns.'],
                            ],
                        ],
                    ],
                    'blocks'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['index', 'block_type', 'label', 'image_fields'],
                            'properties'           => [
                                'index'        => ['type' => 'integer', 'description' => 'Zero-based index among the post\'s ACF blocks. Pass this as block_index to the write tools.'],
                                'block_type'   => ['type' => 'string'],
                                'label'        => ['type' => 'string', 'description' => 'The block\'s heading/title/eyebrow text (tags stripped, ≤80 chars) — a hint for telling blocks apart. Empty when the block has none.'],
                                'heading'      => ['type' => 'string', 'description' => 'Deprecated alias of label.'],
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
                                'fields'       => [
                                    'type'                 => 'object',
                                    'additionalProperties' => true,
                                    'description'          => 'Present with include_fields. Every field by its schema name (as in list-blocks): strings hold HTML for rich-text fields, repeaters are arrays of row objects, images are attachment IDs (0 = empty), true/false fields are booleans.',
                                ],
                                'stray_fields' => [
                                    'type'        => 'array',
                                    'items'       => ['type' => 'string'],
                                    'description' => 'Present when the stored data has keys the live field schema does not know (field-name drift from an older block version). These are ignored by the theme; migrate them with the field-migration tooling of the theme, if it has one.',
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
        if (! current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', __('You are not allowed to edit this post.', 'ai-by-roadmap'), ['status' => 403]);
        }

        $include_fields = ! empty($input['include_fields']);
        $include_ai     = ! empty($input['include_ai_content']);
        $only_index     = isset($input['block_index']) ? (int) $input['block_index'] : null;

        $parsed    = parse_blocks((string) $post->post_content);
        $positions = BlockPatcher::acf_positions($parsed);

        if ($only_index !== null && ! isset($positions[$only_index])) {
            return new WP_Error('block_not_found', sprintf(
                /* translators: 1: requested block index, 2: number of ACF blocks */
                __('No ACF block at index %1$d on this post (it has %2$d).', 'ai-by-roadmap'),
                $only_index,
                count($positions)
            ), ['status' => 404]);
        }

        $blocks = [];
        foreach ($positions as $index => $raw) {
            if ($only_index !== null && $index !== $only_index) {
                continue;
            }
            $blocks[] = self::describe($parsed[$raw], $index, $include_fields, $include_ai);
        }

        return [
            'post_id'      => $post_id,
            'post_type'    => (string) $post->post_type,
            'modified'     => BlockPatcher::modified($post),
            'total_blocks' => BlockPatcher::named_count($parsed),
            'acf_blocks'   => count($positions),
            'fixed_rows'   => BlockPatcher::fixed_rows($parsed),
            'blocks'       => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function describe(array $block, int $index, bool $include_fields, bool $include_ai): array
    {
        $name = (string) $block['blockName'];
        $data = (array) ($block['attrs']['data'] ?? []);
        $defs = AcfBlockFields::definitions($name);

        $image_fields = [];
        foreach (AcfBlockFields::image_fields($name) as $field_name => $label) {
            $value         = $data[$field_name] ?? '';
            $attachment_id = is_numeric($value) ? (int) $value : 0;

            $image_fields[] = [
                'name'          => $field_name,
                'label'         => $label,
                'attachment_id' => $attachment_id,
                'url'           => $attachment_id > 0 ? (string) wp_get_attachment_url($attachment_id) : '',
            ];
        }

        $label = AcfBlockFields::label($data);
        $entry = [
            'index'        => $index,
            'block_type'   => $name,
            'label'        => $label,
            'heading'      => $label,
            'image_fields' => $image_fields,
        ];

        if ($include_fields) {
            $fields = AcfBlockFields::unflatten($data, $defs);
            if (! $include_ai) {
                unset($fields[Plugin::AI_CONTENT_FIELD]);
            }
            $entry['fields'] = $fields;

            $stray = AcfBlockFields::stray_fields($data, $defs);
            if ($stray) {
                $entry['stray_fields'] = $stray;
            }
        }

        return $entry;
    }
}
