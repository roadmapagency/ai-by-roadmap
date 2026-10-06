<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * Where to look at a post: the public permalink when it is published, the
 * authenticated preview URL otherwise. Closes the loop after an edit so an
 * agent (or a person it hands the link to) can check the result.
 */
final class GetPreviewLink
{
    public const ID = 'ai-by-roadmap/get-preview-link';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Get a post\'s preview / public link', 'ai-by-roadmap'),
            'description'         => __('Get the URLs to view a post after editing it: permalink (public once published), preview_url (works for drafts, requires the same logged-in WordPress session or Application Password the agent is using), and edit_link. Use after update-block-fields / assemble-page to hand the user a link, or to screenshot the result. For the rendered HTML of one block without a browser, use render-block.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id' => ['type' => 'integer', 'description' => 'The post to link to.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'status', 'permalink', 'preview_url', 'edit_link', 'is_public'],
                'properties'           => [
                    'post_id'     => ['type' => 'integer'],
                    'post_type'   => ['type' => 'string'],
                    'status'      => ['type' => 'string'],
                    'is_public'   => ['type' => 'boolean', 'description' => 'True when permalink can be opened without logging in.'],
                    'permalink'   => ['type' => 'string'],
                    'preview_url' => ['type' => 'string', 'description' => 'Renders the latest saved content regardless of status; needs an authenticated session.'],
                    'edit_link'   => ['type' => 'string'],
                    'modified'    => ['type' => 'string'],
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

        $status = (string) $post->post_status;

        return [
            'post_id'     => $post_id,
            'post_type'   => (string) $post->post_type,
            'status'      => $status,
            'is_public'   => $status === 'publish',
            'permalink'   => (string) get_permalink($post),
            'preview_url' => (string) get_preview_post_link($post),
            'edit_link'   => (string) get_edit_post_link($post_id, 'raw'),
            'modified'    => BlockPatcher::modified($post),
        ];
    }
}
