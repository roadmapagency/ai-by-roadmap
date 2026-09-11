<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Blocks\CptTemplate;
use WP_Error;

/**
 * Remove one ACF block from an existing post, leaving the others untouched.
 * Refused on post types whose block template is locked ("all" or "insert").
 */
final class RemoveBlock
{
    public const ID = 'ai-by-roadmap/remove-block';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Remove a block from a post', 'ai-by-roadmap'),
            'description'         => __('Remove one ACF block from an existing post by its block_index (from get-post-blocks), leaving all other blocks untouched. Pass expected_block_type so the wrong block is never removed if the list moved. Not allowed on post types with a locked template. Refuses to remove the last remaining block unless allow_empty is true. Returns the new block list.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'block_index'],
                'properties'           => [
                    'post_id'             => ['type' => 'integer', 'description' => 'The post to update.'],
                    'block_index'         => ['type' => 'integer', 'description' => 'Zero-based index among the post\'s ACF blocks, as returned by get-post-blocks.'],
                    'expected_block_type' => ['type' => 'string', 'description' => 'Strongly recommended guard, e.g. "acf/testimonial": refuse if the block at block_index is a different type.'],
                    'expected_modified'   => ['type' => 'string', 'description' => 'Optional guard: the post\'s modified value from get-post-blocks/find-posts. Refuse if the post changed since.'],
                    'allow_empty'         => ['type' => 'boolean', 'default' => false, 'description' => 'Allow removing the post\'s last ACF block, leaving it with no editable content.'],
                ],
            ],
            'output_schema'       => InsertBlock::structural_output_schema(),
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
        $post_id     = (int) $input['post_id'];
        $block_index = (int) $input['block_index'];

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
        if (! CptTemplate::lock_allows($tpl['lock'], 'remove')) {
            return CptTemplate::locked_error($post_type, $tpl['lock'], 'remove');
        }

        $blocks    = parse_blocks((string) $post->post_content);
        $positions = BlockPatcher::acf_positions($blocks);
        $target    = $positions[$block_index] ?? null;

        if ($target === null) {
            return new WP_Error('block_not_found', sprintf(
                /* translators: 1: requested block index, 2: number of ACF blocks */
                __('No ACF block at index %1$d on this post (it has %2$d). Call get-post-blocks to list them.', 'ai-by-roadmap'),
                $block_index,
                count($positions)
            ), ['status' => 404]);
        }

        $block_type = (string) $blocks[$target]['blockName'];
        $guard      = BlockPatcher::check_type($block_type, isset($input['expected_block_type']) ? (string) $input['expected_block_type'] : null, $block_index);
        if (is_wp_error($guard)) {
            return $guard;
        }

        if (count($positions) === 1 && empty($input['allow_empty'])) {
            return new WP_Error('would_empty_post', __('This is the post\'s only ACF block. Pass allow_empty: true if you really want to leave the post without content.', 'ai-by-roadmap'), ['status' => 409]);
        }

        $blocks = BlockPatcher::remove_at($blocks, $target);

        $types = array_map(static fn($raw) => (string) $blocks[$raw]['blockName'], BlockPatcher::acf_positions($blocks));
        $check = CptTemplate::validate($tpl, $post_type, $types, __('The removal would violate the template and was not applied.', 'ai-by-roadmap'));
        if (is_wp_error($check)) {
            return $check;
        }

        $modified = BlockPatcher::save($post_id, $blocks);
        if (is_wp_error($modified)) {
            return $modified;
        }

        return [
            'success'    => true,
            'post_id'    => $post_id,
            'block_type' => $block_type,
            'acf_blocks' => count($types),
            'blocks'     => BlockPatcher::summary($blocks),
            'modified'   => $modified,
            'edit_link'  => (string) get_edit_post_link($post_id, 'raw'),
            'permalink'  => (string) get_permalink($post_id),
        ];
    }
}
