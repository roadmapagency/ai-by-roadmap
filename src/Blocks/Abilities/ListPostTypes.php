<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

/**
 * Public discovery ability. Returns every public post type with the metadata an
 * external agent needs to place a page correctly: its key, rewrite slug,
 * supports, and — for CPTs that use one — the locked Gutenberg block template.
 *
 * Pairs with list-blocks: list-blocks says what blocks exist, list-post-types
 * says where a page should live and (when locked) which blocks it must contain.
 * Without this, an agent has no way to know a /programs/… route maps to the
 * "program" CPT rather than a plain page.
 */
final class ListPostTypes
{
    public const ID = 'ai-by-roadmap/list-post-types';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => ['show_in_rest' => true],
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('List available post types', 'ai-by-roadmap'),
            'description'         => __('Return every public WordPress post type with its key, labels, rewrite slug, supports, and locked block template (if any). Call this before create-page/assemble-page to choose the right post type: match the source page route to a type\'s rewrite_slug (e.g. a /programs/… route → the type whose rewrite_slug is "programs"), not the generic "page". When a type has a template with template_lock set, supply your blocks in that exact order and of those exact types.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => new \stdClass(),
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_types'],
                'properties'           => [
                    'post_types' => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => true,
                            'required'             => ['name', 'label', 'rewrite_slug'],
                            'properties'           => [
                                'name'           => ['type' => 'string'],
                                'label'          => ['type' => 'string'],
                                'singular_label' => ['type' => 'string'],
                                'description'    => ['type' => 'string'],
                                'hierarchical'   => ['type' => 'boolean'],
                                'rewrite_slug'   => ['type' => 'string'],
                                'supports'       => ['type' => 'array', 'items' => ['type' => 'string']],
                                'template_lock'  => ['type' => 'string'],
                                'template'       => ['type' => 'array', 'items' => ['type' => 'string']],
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
     * @return array<string, mixed>
     */
    public static function execute(array $input): array
    {
        $post_types = [];

        foreach (get_post_types(['public' => true], 'objects') as $pt) {
            // Attachments are public but never an editorial target.
            if ($pt->name === 'attachment') {
                continue;
            }

            $rewrite_slug = $pt->name;
            if (is_array($pt->rewrite) && ! empty($pt->rewrite['slug'])) {
                $rewrite_slug = (string) $pt->rewrite['slug'];
            }

            // Same source of truth the enforcement uses, so the advertised
            // template can never drift from what assemble-page requires.
            $tpl = \Roadmap\AiByRoadmap\Blocks\CptTemplate::for_post_type($pt->name);

            $post_types[] = [
                'name'           => $pt->name,
                'label'          => (string) ($pt->labels->name ?? $pt->label),
                'singular_label' => (string) ($pt->labels->singular_name ?? ''),
                'description'    => (string) $pt->description,
                'hierarchical'   => (bool) $pt->hierarchical,
                'rewrite_slug'   => $rewrite_slug,
                'supports'       => array_keys(get_all_post_type_supports($pt->name)),
                'template_lock'  => $tpl['lock'],
                'template'       => $tpl['blocks'],
            ];
        }

        return ['post_types' => $post_types];
    }
}
