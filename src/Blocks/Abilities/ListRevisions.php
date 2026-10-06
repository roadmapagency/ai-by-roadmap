<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * The undo history of a post. Every write tool creates a revision, so an
 * agent (or the person supervising it) can always see what changed and roll
 * back with restore-revision.
 */
final class ListRevisions
{
    public const ID = 'ai-by-roadmap/list-revisions';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('List a post\'s revisions', 'ai-by-roadmap'),
            'description'         => __('List the saved revisions of a post, newest first, with date, author, and ACF block count — the undo history behind every write tool. Pick a revision_id and pass it to restore-revision to roll the post\'s content back to that state (the rollback itself becomes a new revision, so it is reversible).', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id' => ['type' => 'integer'],
                    'limit'   => ['type' => 'integer', 'description' => 'Max revisions to return (default 20, max 100).'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'modified', 'revisions'],
                'properties'           => [
                    'post_id'   => ['type' => 'integer'],
                    'modified'  => ['type' => 'string'],
                    'revisions' => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['revision_id', 'date', 'author', 'acf_blocks', 'is_autosave', 'is_current'],
                            'properties'           => [
                                'revision_id' => ['type' => 'integer'],
                                'date'        => ['type' => 'string'],
                                'author'      => ['type' => 'string'],
                                'title'       => ['type' => 'string'],
                                'acf_blocks'  => ['type' => 'integer'],
                                'is_autosave' => ['type' => 'boolean'],
                                'is_current'  => ['type' => 'boolean', 'description' => 'Content identical to the live post.'],
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

        $limit = isset($input['limit']) ? max(1, min(100, (int) $input['limit'])) : 20;
        $rows  = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => $limit]) as $rev) {
            $author = get_userdata((int) $rev->post_author);
            $rows[] = [
                'revision_id' => (int) $rev->ID,
                'date'        => (string) $rev->post_modified,
                'author'      => $author ? (string) $author->display_name : '',
                'title'       => (string) $rev->post_title,
                'acf_blocks'  => count(BlockPatcher::acf_positions(parse_blocks((string) $rev->post_content))),
                'is_autosave' => wp_is_post_autosave($rev) !== false,
                'is_current'  => (string) $rev->post_content === (string) $post->post_content,
            ];
        }

        return ['post_id' => $post_id, 'modified' => BlockPatcher::modified($post), 'revisions' => $rows];
    }
}
