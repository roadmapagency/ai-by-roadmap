<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\ACFTransformer;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Blocks\BlockRegistry;
use Roadmap\AiByRoadmap\Blocks\CptTemplate;
use Roadmap\AiByRoadmap\Plugin;
use WP_Error;

/**
 * Insert one filled ACF block into an existing post at a given position,
 * without touching the other blocks. Refused on post types whose block
 * template is locked (template_lock "all" or "insert").
 */
final class InsertBlock
{
    public const ID = 'ai-by-roadmap/insert-block';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Insert a block into a post', 'ai-by-roadmap'),
            'description'         => __('Insert one new ACF block into an existing post at a position, leaving all other blocks untouched. Fill the fields yourself using the block\'s schema from list-blocks (same rules as assemble-page: inline HTML for rich text, attachment IDs for images, include an ai_content string with the verbatim source when you have one). at_index is in the ACF index space of get-post-blocks: the new block lands BEFORE the block currently at that index; at_index equal to acf_blocks appends. Not allowed on post types with a locked template (use update-block-fields there). Returns the new block list so you do not need to re-read.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'at_index', 'type', 'fields'],
                'properties'           => [
                    'post_id'           => ['type' => 'integer', 'description' => 'The post to update.'],
                    'at_index'          => ['type' => 'integer', 'description' => 'Zero-based ACF index to insert at (the block lands before the block currently there). Use acf_blocks from get-post-blocks to append.'],
                    'type'              => [
                        'type'        => 'string',
                        'description' => 'Block type ID (e.g. acf/testimonial). Must be one returned by list-blocks.',
                        'enum'        => array_keys(BlockRegistry::get_blocks()),
                    ],
                    'fields'            => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                        'description'          => 'Field values for the new block, matching its schema from list-blocks. May include ai_content.',
                    ],
                    'align'             => ['type' => 'string', 'description' => 'Optional block alignment attribute (e.g. "full").'],
                    'expected_modified' => ['type' => 'string', 'description' => 'Optional guard: the post\'s modified value from get-post-blocks/find-posts. Refuse if the post changed since.'],
                ],
            ],
            'output_schema'       => self::structural_output_schema(),
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * Output schema shared by insert-block, remove-block and move-block.
     *
     * @return array<string, mixed>
     */
    public static function structural_output_schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['success', 'post_id', 'acf_blocks', 'blocks', 'modified'],
            'properties'           => [
                'success'     => ['type' => 'boolean'],
                'post_id'     => ['type' => 'integer'],
                'block_index' => ['type' => 'integer', 'description' => 'ACF index of the affected block after the operation (insert/move).'],
                'block_type'  => ['type' => 'string'],
                'acf_blocks'  => ['type' => 'integer', 'description' => 'Number of ACF blocks after the operation.'],
                'blocks'      => [
                    'type'        => 'array',
                    'description' => 'The post\'s ACF blocks after the operation, in order.',
                    'items'       => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['index', 'block_type', 'label'],
                        'properties'           => [
                            'index'      => ['type' => 'integer'],
                            'block_type' => ['type' => 'string'],
                            'label'      => ['type' => 'string'],
                        ],
                    ],
                ],
                'modified'    => ['type' => 'string', 'description' => 'The post\'s new modified value; use as expected_modified for a follow-up write.'],
                'edit_link'   => ['type' => 'string'],
                'permalink'   => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function execute(array $input)
    {
        $post_id  = (int) $input['post_id'];
        $at_index = (int) $input['at_index'];
        $type     = (string) $input['type'];
        $fields   = (array) ($input['fields'] ?? []);
        $align    = isset($input['align']) ? trim((string) $input['align']) : '';

        $post = get_post($post_id);
        if (! $post) {
            return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
        }
        if (! current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', __('You are not allowed to edit this post.', 'ai-by-roadmap'), ['status' => 403]);
        }

        $guard = BlockPatcher::check_modified($post, isset($input['expected_modified']) ? (string) $input['expected_modified'] : null);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $post_type = (string) $post->post_type;
        $tpl       = CptTemplate::for_post_type($post_type);
        if (! CptTemplate::lock_allows($tpl['lock'], 'insert')) {
            return CptTemplate::locked_error($post_type, $tpl['lock'], 'insert');
        }

        $registered = BlockRegistry::get_blocks();
        if (! isset($registered[$type])) {
            return new WP_Error('invalid_block_type', sprintf(
                /* translators: 1: block type, 2: list of registered block IDs */
                __('Block "%1$s" is not registered. Registered: %2$s.', 'ai-by-roadmap'),
                $type,
                implode(', ', array_keys($registered))
            ), ['status' => 400]);
        }

        $defs = AcfBlockFields::definitions($type);
        if ($defs !== []) {
            $valid = AcfBlockFields::validate($fields, $defs, $type);
            if (is_wp_error($valid)) {
                return $valid;
            }
        }

        $blocks = parse_blocks((string) $post->post_content);
        $count  = count(BlockPatcher::acf_positions($blocks));
        if ($at_index < 0 || $at_index > $count) {
            return new WP_Error('index_out_of_range', sprintf(
                /* translators: 1: requested index, 2: number of ACF blocks */
                __('at_index %1$d is out of range: the post has %2$d ACF blocks, so valid values are 0–%2$d.', 'ai-by-roadmap'),
                $at_index,
                $count
            ), ['status' => 400]);
        }

        // ai_content holds the verbatim source slice; default to empty so a
        // block that legitimately has none still serializes (as assemble-page).
        $fields[Plugin::AI_CONTENT_FIELD] = (string) ($fields[Plugin::AI_CONTENT_FIELD] ?? '');

        $serialized = (new ACFTransformer())->convert([$type => $fields], $align);
        $new_block  = null;
        foreach (parse_blocks($serialized) as $candidate) {
            if (! empty($candidate['blockName'])) {
                $new_block = $candidate;
                break;
            }
        }
        if ($new_block === null) {
            return new WP_Error('serialize_failed', __('Could not serialize the new block.', 'ai-by-roadmap'), ['status' => 500]);
        }

        $blocks = BlockPatcher::insert_at($blocks, $at_index, $new_block);

        // Belt and braces: the lock check above should make this a no-op.
        $types = array_map(static fn($raw) => (string) $blocks[$raw]['blockName'], BlockPatcher::acf_positions($blocks));
        $check = CptTemplate::validate($tpl, $post_type, $types, __('The insert would violate the template and was not applied.', 'ai-by-roadmap'));
        if (is_wp_error($check)) {
            return $check;
        }

        $modified = BlockPatcher::save($post_id, $blocks);
        if (is_wp_error($modified)) {
            return $modified;
        }

        return [
            'success'     => true,
            'post_id'     => $post_id,
            'block_index' => $at_index,
            'block_type'  => $type,
            'acf_blocks'  => count($types),
            'blocks'      => BlockPatcher::summary($blocks),
            'modified'    => $modified,
            'edit_link'   => (string) get_edit_post_link($post_id, 'raw'),
            'permalink'   => (string) get_permalink($post_id),
        ];
    }
}
