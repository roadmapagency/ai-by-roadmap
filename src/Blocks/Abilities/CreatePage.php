<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

final class CreatePage
{
    public const ID = 'ai-by-roadmap/create-page';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Create an empty page', 'ai-by-roadmap'),
            'description'         => __('Create a new empty WordPress post of the given post type and return its ID. Pass the returned post_id to compose-page to fill it with content. Call find-posts first — if a matching (often empty placeholder) page already exists, fill it via assemble-page instead of creating a duplicate. Call list-post-types to choose the right post_type — match the source page route to a type\'s rewrite_slug (e.g. a /programs/… route → the type with slug "programs"), not the generic "page".', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_type'],
                'properties'           => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Registered WordPress post type (e.g. "page", "post", or a CPT like "program"). Use list-post-types to discover options and match the source route to a type\'s rewrite_slug.',
                    ],
                    'title'     => [
                        'type'        => 'string',
                        'description' => 'Title for the new post. Defaults to "New {post_type}".',
                    ],
                    'slug'      => [
                        'type'        => 'string',
                        'description' => 'URL slug for the post. Defaults to a sanitized version of the title.',
                    ],
                    'parent_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID of the parent post. Use this to reflect folder hierarchy.',
                    ],
                    'status'    => [
                        'type'        => 'string',
                        'enum'        => ['draft', 'publish', 'private'],
                        'description' => 'Initial post status. Defaults to "draft".',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id', 'post_type', 'status', 'edit_url'],
                'properties'           => [
                    'post_id'   => ['type' => 'integer'],
                    'post_type' => ['type' => 'string'],
                    'status'    => ['type' => 'string'],
                    'edit_url'  => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $post_type = (string) $input['post_type'];
        $status    = (string) ($input['status'] ?? 'draft');
        $title     = (string) ($input['title'] ?? sprintf('New %s', $post_type));

        if (! post_type_exists($post_type)) {
            return new \WP_Error('invalid_post_type', sprintf('Post type "%s" is not registered.', $post_type));
        }

        $args = [
            'post_type'    => $post_type,
            'post_title'   => $title,
            'post_status'  => $status,
            'post_content' => '',
        ];

        if (! empty($input['slug'])) {
            $args['post_name'] = sanitize_title((string) $input['slug']);
        }

        if (! empty($input['parent_id'])) {
            $args['post_parent'] = (int) $input['parent_id'];
        }

        $post_id = wp_insert_post($args, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return [
            'post_id'   => $post_id,
            'post_type' => $post_type,
            'status'    => $status,
            'edit_url'  => get_edit_post_link($post_id, 'raw'),
        ];
    }
}
