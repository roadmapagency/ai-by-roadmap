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
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Update a post\'s title, slug, status, excerpt, parent, image or terms', 'ai-by-roadmap'),
            'description'         => __('Change a post\'s own attributes without touching its blocks: title, slug, status, excerpt, parent page, menu order, featured image, page template, taxonomy terms. Supply only the attributes to change. Publishing requires the publish capability for that post. SEO title / meta description are Yoast\'s: use yoast-seo/update-post-seo-data when available. Use find-posts to get the post_id; pass its modified value as expected_modified to guard against concurrent edits. For block content use update-block-fields.', 'ai-by-roadmap'),
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
                    'parent_id'         => ['type' => 'integer', 'description' => 'Parent post ID for hierarchical types (0 = top level).'],
                    'menu_order'        => ['type' => 'integer'],
                    'featured_image'    => ['type' => 'integer', 'description' => 'Attachment ID for the featured image (0 removes it).'],
                    'template'          => ['type' => 'string', 'description' => 'Page template file name (e.g. "templates/landing.php") or "default".'],
                    'terms'             => [
                        'type'                 => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'description'          => 'Taxonomy → list of term slugs or names to set (replaces that taxonomy\'s terms). Missing terms are created for non-hierarchical taxonomies.',
                    ],
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
                    'parent_id'      => ['type' => 'integer'],
                    'menu_order'     => ['type' => 'integer'],
                    'featured_image' => ['type' => 'integer'],
                    'template'       => ['type' => 'string'],
                    'terms'          => ['type' => 'object', 'additionalProperties' => true],
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

        if (array_key_exists('parent_id', $input)) {
            $parent = (int) $input['parent_id'];
            if ($parent > 0) {
                $p = get_post($parent);
                if (! $p || $p->post_type !== $post->post_type || $parent === $post_id) {
                    return new WP_Error('invalid_parent', __('parent_id must be another post of the same type.', 'ai-by-roadmap'), ['status' => 400]);
                }
            }
            if ($parent !== (int) $post->post_parent) {
                $update['post_parent'] = $parent;
                $changed[]             = 'parent_id';
            }
        }

        if (array_key_exists('menu_order', $input) && (int) $input['menu_order'] !== (int) $post->menu_order) {
            $update['menu_order'] = (int) $input['menu_order'];
            $changed[]            = 'menu_order';
        }

        $side_effects = [];

        if (array_key_exists('featured_image', $input)) {
            $thumb = (int) $input['featured_image'];
            if ($thumb > 0 && ! wp_attachment_is_image($thumb)) {
                return new WP_Error('invalid_attachment', __('featured_image is not an image attachment.', 'ai-by-roadmap'), ['status' => 400]);
            }
            if ($thumb !== (int) get_post_thumbnail_id($post_id)) {
                $side_effects[] = static function () use ($post_id, $thumb): void {
                    $thumb > 0 ? set_post_thumbnail($post_id, $thumb) : delete_post_thumbnail($post_id);
                };
                $changed[] = 'featured_image';
            }
        }

        if (array_key_exists('template', $input)) {
            $template = (string) $input['template'];
            $current  = (string) get_page_template_slug($post_id);
            if ($template !== 'default' && $template !== '') {
                $available = wp_get_theme()->get_page_templates($post, (string) $post->post_type);
                if (! isset($available[$template])) {
                    return new WP_Error('invalid_template', sprintf(
                        /* translators: 1: template, 2: available templates */
                        __('Template "%1$s" is not available for this post type. Available: %2$s.', 'ai-by-roadmap'),
                        $template,
                        $available ? implode(', ', array_keys($available)) : __('(none)', 'ai-by-roadmap')
                    ), ['status' => 400]);
                }
            }
            if ($template !== $current && ! ($template === 'default' && $current === '')) {
                $update['page_template'] = $template === '' ? 'default' : $template;
                $changed[]               = 'template';
            }
        }

        if (array_key_exists('terms', $input) && is_array($input['terms'])) {
            foreach ($input['terms'] as $taxonomy => $terms) {
                $taxonomy = (string) $taxonomy;
                if (! taxonomy_exists($taxonomy) || ! is_object_in_taxonomy((string) $post->post_type, $taxonomy)) {
                    return new WP_Error('invalid_taxonomy', sprintf(
                        /* translators: 1: taxonomy, 2: post type, 3: valid taxonomies */
                        __('"%1$s" is not a taxonomy of post type "%2$s". Valid: %3$s.', 'ai-by-roadmap'),
                        $taxonomy,
                        (string) $post->post_type,
                        implode(', ', get_object_taxonomies((string) $post->post_type)) ?: __('(none)', 'ai-by-roadmap')
                    ), ['status' => 400]);
                }
                $terms   = array_values(array_map('strval', (array) $terms));
                $current = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
                $current = is_wp_error($current) ? [] : $current;
                sort($current);
                $wanted = array_map('sanitize_title', $terms);
                sort($wanted);
                if ($wanted === $current) {
                    continue;
                }
                $side_effects[] = static function () use ($post_id, $taxonomy, $terms): void {
                    $ids = [];
                    foreach ($terms as $term) {
                        $t = get_term_by('slug', sanitize_title($term), $taxonomy) ?: get_term_by('name', $term, $taxonomy);
                        if (! $t && ! is_taxonomy_hierarchical($taxonomy)) {
                            $created = wp_insert_term($term, $taxonomy);
                            $t       = is_wp_error($created) ? null : get_term((int) $created['term_id'], $taxonomy);
                        }
                        if ($t && ! is_wp_error($t)) {
                            $ids[] = (int) $t->term_id;
                        }
                    }
                    wp_set_object_terms($post_id, $ids, $taxonomy);
                };
                $changed[] = 'terms.' . $taxonomy;
            }
        }

        $accepted = ['title', 'slug', 'status', 'excerpt', 'parent_id', 'menu_order', 'featured_image', 'template', 'terms'];
        if (! array_intersect_key($input, array_flip($accepted))) {
            return new WP_Error('nothing_to_update', sprintf(
                /* translators: %s: comma-separated attribute names */
                __('Supply at least one of: %s.', 'ai-by-roadmap'),
                implode(', ', $accepted)
            ), ['status' => 400]);
        }

        if ($changed !== []) {
            if (count($update) > 1) {
                $result = wp_update_post($update, true);
                if (is_wp_error($result)) {
                    return $result;
                }
            }
            foreach ($side_effects as $apply) {
                $apply();
            }
            clean_post_cache($post_id);
            $post = get_post($post_id) ?: $post;
        }

        $terms_out = [];
        foreach (get_object_taxonomies((string) $post->post_type) as $taxonomy) {
            $slugs = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
            $terms_out[$taxonomy] = is_wp_error($slugs) ? [] : array_values($slugs);
        }

        return [
            'success'        => true,
            'post_id'        => $post_id,
            'changed_fields' => $changed,
            'title'          => (string) $post->post_title,
            'slug'           => (string) $post->post_name,
            'status'         => (string) $post->post_status,
            'excerpt'        => (string) $post->post_excerpt,
            'parent_id'      => (int) $post->post_parent,
            'menu_order'     => (int) $post->menu_order,
            'featured_image' => (int) get_post_thumbnail_id($post_id),
            'template'       => (string) get_page_template_slug($post_id) ?: 'default',
            'terms'          => $terms_out ?: new \stdClass(),
            'permalink'      => (string) get_permalink($post_id),
            'modified'       => BlockPatcher::modified($post),
            'edit_link'      => (string) get_edit_post_link($post_id, 'raw'),
        ];
    }
}
