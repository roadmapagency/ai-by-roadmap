<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Blocks\CptTemplate;
use WP_Error;

/**
 * Reorder one ACF block within an existing post. Allowed unless the post
 * type's template is fully locked (template_lock "all"); an "insert" lock
 * permits reordering, matching the editor.
 */
final class MoveBlock
{
    public const ID = 'ai-by-roadmap/move-block';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Move a block within a post', 'ai-by-roadmap'),
            'description'         => __('Move one ACF block to a new position in an existing post, leaving every block\'s content untouched. Indices are the ACF indices from get-post-blocks: from_index is the block to move, to_index is the index it should have AFTER the move. Pass expected_block_type as a guard. Not allowed when the post type\'s template_lock is "all". Returns the new block list.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'from_index', 'to_index'],
                'properties'           => [
                    'post_id'             => ['type' => 'integer', 'description' => 'The post to update.'],
                    'from_index'          => ['type' => 'integer', 'description' => 'Current zero-based ACF index of the block to move.'],
                    'to_index'            => ['type' => 'integer', 'description' => 'Zero-based ACF index the block should occupy after the move.'],
                    'expected_block_type' => ['type' => 'string', 'description' => 'Optional guard, e.g. "acf/cta": refuse if the block at from_index is a different type.'],
                    'expected_modified'   => ['type' => 'string', 'description' => 'Optional guard: the post\'s modified value from get-post-blocks/find-posts. Refuse if the post changed since.'],
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
        $post_id = (int) $input['post_id'];
        $from    = (int) $input['from_index'];
        $to      = (int) $input['to_index'];

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
        if (! CptTemplate::lock_allows($tpl['lock'], 'move')) {
            return CptTemplate::locked_error($post_type, $tpl['lock'], 'move');
        }

        $blocks    = parse_blocks((string) $post->post_content);
        $positions = BlockPatcher::acf_positions($blocks);
        $count     = count($positions);

        foreach (['from_index' => $from, 'to_index' => $to] as $label => $index) {
            if ($index < 0 || $index >= $count) {
                return new WP_Error('index_out_of_range', sprintf(
                    /* translators: 1: parameter name, 2: value, 3: number of ACF blocks */
                    __('%1$s %2$d is out of range: the post has %3$d ACF blocks (indices 0–%4$d).', 'ai-by-roadmap'),
                    $label,
                    $index,
                    $count,
                    max(0, $count - 1)
                ), ['status' => 400]);
            }
        }

        $raw        = $positions[$from];
        $block_type = (string) $blocks[$raw]['blockName'];
        $guard      = BlockPatcher::check_type($block_type, isset($input['expected_block_type']) ? (string) $input['expected_block_type'] : null, $from);
        if (is_wp_error($guard)) {
            return $guard;
        }

        $result = [
            'success'     => true,
            'post_id'     => $post_id,
            'block_index' => $to,
            'block_type'  => $block_type,
            'acf_blocks'  => $count,
            'blocks'      => BlockPatcher::summary($blocks),
            'modified'    => BlockPatcher::modified($post),
            'edit_link'   => (string) get_edit_post_link($post_id, 'raw'),
            'permalink'   => (string) get_permalink($post_id),
        ];

        if ($from === $to) {
            return $result; // nothing to do
        }

        $moving = $blocks[$raw];
        $blocks = BlockPatcher::remove_at($blocks, $raw);
        // After removal the list has count-1 blocks; inserting before index $to
        // (or appending when $to is the last slot) gives the block index $to.
        $blocks = BlockPatcher::insert_at($blocks, $to, $moving);

        $types = array_map(static fn($r) => (string) $blocks[$r]['blockName'], BlockPatcher::acf_positions($blocks));
        $check = CptTemplate::validate($tpl, $post_type, $types, __('The move would violate the template and was not applied.', 'ai-by-roadmap'));
        if (is_wp_error($check)) {
            return $check;
        }

        $modified = BlockPatcher::save($post_id, $blocks);
        if (is_wp_error($modified)) {
            return $modified;
        }

        $result['blocks']   = BlockPatcher::summary($blocks);
        $result['modified'] = $modified;

        return $result;
    }
}
