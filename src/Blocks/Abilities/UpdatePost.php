<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * Update a post's own attributes — title, slug, status, excerpt — without
 * touching its block content. Fills the gap where the only way to fix a title
 * used to be a full assemble-page rewrite.
 */
final class UpdatePost
{
    public const ID = 'ai-by-roadmap/update-post';

    private const STATUSES = ['draft', 'pending', 'publish', 'private'];

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Update a post\'s title, slug, status or excerpt', 'ai-by-roadmap'),
            'description'         => __('Change a post\'s title, slug, status and/or excerpt without touching its blocks. Supply only the attributes to change. Publishing requires the publish capability for that post. Use find-posts to get the post_id; pass its modified value as expected_modified to guard against concurrent edits. For block content use update-block-fields.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id'           => ['type' => 'integer', 'description' => 'The post to update.'],
                    'title'             => ['type' => 'string', 'description' => 'New post title.'],
                    'slug'              => ['type' => 'string', 'description' => 'New URL slug (post_name). Sanitized; WordPress appends -2 etc. if it collides.'],
                    'status'            => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'New post status.'],
                    'excerpt'           => ['type' => 'string', 'description' => 'New excerpt (plain text or simple HTML).'],
                    'expected_modified' => ['type' => 'string', 'description' => 'Optional guard: the post\'s modified value from find-posts/get-post-blocks. Refuse if the post changed since.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'post_id', 'changed_fields', 'title', 'slug', 'status', 'modified'],
                'properties'           => [
                    'success'        => ['type' => 'boolean'],
                    'post_id'        => ['type' => 'integer'],
                    'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'title'          => ['type' => 'string'],
                    'slug'           => ['type' => 'string'],
                    'status'         => ['type' => 'string'],
                    'excerpt'        => ['type' => 'string'],
                    'permalink'      => ['type' => 'string'],
                    'modified'       => ['type' => 'string'],
                    'edit_link'      => ['type' => 'string'],
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

        $update  = ['ID' => $post_id];
        $changed = [];

        if (array_key_exists('title', $input)) {
            $title = (string) $input['title'];
            if ($title !== (string) $post->post_title) {
                $update['post_title'] = wp_slash($title);
                $changed[]            = 'title';
            }
        }

        if (array_key_exists('slug', $input)) {
            $slug = sanitize_title((string) $input['slug']);
            if ($slug === '') {
                return new WP_Error('invalid_slug', __('slug sanitizes to an empty string.', 'ai-by-roadmap'), ['status' => 400]);
            }
            if ($slug !== (string) $post->post_name) {
                $update['post_name'] = $slug;
                $changed[]           = 'slug';
            }
        }

        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (! in_array($status, self::STATUSES, true)) {
                return new WP_Error('invalid_status', sprintf(
                    /* translators: %s: comma-separated statuses */
                    __('status must be one of: %s.', 'ai-by-roadmap'),
                    implode(', ', self::STATUSES)
                ), ['status' => 400]);
            }
            if ($status !== (string) $post->post_status) {
                if (in_array($status, ['publish', 'private'], true) && ! current_user_can('publish_post', $post_id)) {
                    return new WP_Error('forbidden', __('You are not allowed to publish this post.', 'ai-by-roadmap'), ['status' => 403]);
                }
                $update['post_status'] = $status;
                $changed[]             = 'status';
            }
        }

        if (array_key_exists('excerpt', $input)) {
            $excerpt = (string) $input['excerpt'];
            if ($excerpt !== (string) $post->post_excerpt) {
                $update['post_excerpt'] = wp_slash($excerpt);
                $changed[]              = 'excerpt';
            }
        }

        if (count($update) === 1 && ! array_intersect_key($input, ['title' => 1, 'slug' => 1, 'status' => 1, 'excerpt' => 1])) {
            return new WP_Error('nothing_to_update', __('Supply at least one of title, slug, status or excerpt.', 'ai-by-roadmap'), ['status' => 400]);
        }

        if ($changed !== []) {
            $result = wp_update_post($update, true);
            if (is_wp_error($result)) {
                return $result;
            }
            clean_post_cache($post_id);
            $post = get_post($post_id) ?: $post;
        }

        return [
            'success'        => true,
            'post_id'        => $post_id,
            'changed_fields' => $changed,
            'title'          => (string) $post->post_title,
            'slug'           => (string) $post->post_name,
            'status'         => (string) $post->post_status,
            'excerpt'        => (string) $post->post_excerpt,
            'permalink'      => (string) get_permalink($post_id),
            'modified'       => BlockPatcher::modified($post),
            'edit_link'      => (string) get_edit_post_link($post_id, 'raw'),
        ];
    }
}
