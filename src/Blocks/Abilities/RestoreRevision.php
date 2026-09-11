<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * Roll a post back to one of its revisions. The restore itself is saved as a
 * new revision, so nothing is lost and the action can be undone the same way.
 */
final class RestoreRevision
{
    public const ID = 'ai-by-roadmap/restore-revision';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, true, false),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Restore a post revision', 'ai-by-roadmap'),
            'description'         => __('Restore a post\'s title, content and excerpt from one of its revisions (revision_id from list-revisions) — the undo for any edit made with the block tools. The current state is kept as a revision first, so this is reversible. Pass expected_modified to make sure nobody edited the post in between. Returns the restored block list.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'revision_id'],
                'properties'           => [
                    'post_id'           => ['type' => 'integer'],
                    'revision_id'       => ['type' => 'integer', 'description' => 'From list-revisions. Must belong to post_id.'],
                    'expected_modified' => ['type' => 'string', 'description' => 'Optional guard: the post\'s modified value from list-revisions/get-post-blocks.'],
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
        $revision_id = (int) $input['revision_id'];

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

        $revision = wp_get_post_revision($revision_id);
        if (! $revision || (int) $revision->post_parent !== $post_id) {
            return new WP_Error('revision_not_found', sprintf(
                /* translators: 1: revision id, 2: post id */
                __('Revision %1$d does not belong to post %2$d. Call list-revisions to see its revisions.', 'ai-by-roadmap'),
                $revision_id,
                $post_id
            ), ['status' => 404]);
        }

        // Keep the current state as a revision so the restore is reversible
        // even when nothing else would have triggered one.
        wp_save_post_revision($post_id);

        $restored = wp_restore_post_revision($revision_id);
        if ($restored === null || $restored === false) {
            return new WP_Error('restore_failed', __('WordPress could not restore that revision (it may be identical to the current content).', 'ai-by-roadmap'), ['status' => 500]);
        }

        clean_post_cache($post_id);
        $post   = get_post($post_id);
        $blocks = parse_blocks((string) ($post ? $post->post_content : ''));

        return [
            'success'    => true,
            'post_id'    => $post_id,
            'block_type' => '',
            'acf_blocks' => count(BlockPatcher::acf_positions($blocks)),
            'blocks'     => BlockPatcher::summary($blocks),
            'modified'   => $post ? BlockPatcher::modified($post) : '',
            'edit_link'  => (string) get_edit_post_link($post_id, 'raw'),
            'permalink'  => (string) get_permalink($post_id),
        ];
    }
}
