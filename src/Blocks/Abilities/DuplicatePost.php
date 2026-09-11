<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use WP_Error;

/**
 * Clone an existing post into a new draft: blocks, excerpt, parent, order,
 * template, featured image, taxonomy terms and post meta (ACF post fields
 * such as a location's address). On locked post types this is the cheapest
 * way to create a new entry — duplicate a good one, then patch its fields.
 */
final class DuplicatePost
{
    public const ID = 'ai-by-roadmap/duplicate-post';

    /** Meta keys that belong to the original only. */
    private const SKIP_META = ['_edit_lock', '_edit_last', '_dp_original', '_wp_old_slug', '_wp_old_date', '_thumbnail_id'];

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, false),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Duplicate a post', 'ai-by-roadmap'),
            'description'         => __('Create a new draft by copying an existing post: all blocks, excerpt, parent, menu order, page template, featured image, taxonomy terms and post meta (e.g. a location\'s address fields). Same post type as the source, so locked templates are satisfied automatically — the fastest way to create a new program/location/team member: duplicate the closest existing one, then update-block-fields / update-post the differences. Returns the new post_id and its block list.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['post_id'],
                'properties'           => [
                    'post_id'      => ['type' => 'integer', 'description' => 'The post to copy.'],
                    'title'        => ['type' => 'string', 'description' => 'Title for the copy. Default: "Copy of <original title>".'],
                    'slug'         => ['type' => 'string', 'description' => 'Slug for the copy. Default: derived from the title.'],
                    'status'       => ['type' => 'string', 'enum' => ['draft', 'pending', 'private'], 'default' => 'draft', 'description' => 'Copies are never published directly; publish with update-post after review.'],
                    'include_meta' => ['type' => 'boolean', 'default' => true, 'description' => 'Copy post meta (ACF post fields, SEO fields). Editorial locks and scores are never copied.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'post_id', 'source_post_id', 'title', 'slug', 'status', 'post_type', 'acf_blocks', 'blocks', 'edit_link'],
                'properties'           => [
                    'success'        => ['type' => 'boolean'],
                    'post_id'        => ['type' => 'integer'],
                    'source_post_id' => ['type' => 'integer'],
                    'title'          => ['type' => 'string'],
                    'slug'           => ['type' => 'string'],
                    'status'         => ['type' => 'string'],
                    'post_type'      => ['type' => 'string'],
                    'acf_blocks'     => ['type' => 'integer'],
                    'blocks'         => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                    'meta_copied'    => ['type' => 'integer'],
                    'terms_copied'   => ['type' => 'integer'],
                    'modified'       => ['type' => 'string'],
                    'preview_url'    => ['type' => 'string'],
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
        $source_id = (int) $input['post_id'];
        $source    = get_post($source_id);
        if (! $source || $source->post_type === 'revision') {
            return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
        }
        if (! current_user_can('edit_post', $source_id)) {
            return new WP_Error('forbidden', __('You are not allowed to read this post.', 'ai-by-roadmap'), ['status' => 403]);
        }
        $pto = get_post_type_object((string) $source->post_type);
        if (! $pto || ! current_user_can($pto->cap->create_posts)) {
            return new WP_Error('forbidden', sprintf(
                /* translators: %s: post type */
                __('You are not allowed to create "%s" posts.', 'ai-by-roadmap'),
                (string) $source->post_type
            ), ['status' => 403]);
        }

        $status = (string) ($input['status'] ?? 'draft');
        if (! in_array($status, ['draft', 'pending', 'private'], true)) {
            $status = 'draft';
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = sprintf(
                /* translators: %s: original post title */
                __('Copy of %s', 'ai-by-roadmap'),
                (string) $source->post_title
            );
        }

        $args = [
            'post_type'    => (string) $source->post_type,
            'post_status'  => $status,
            'post_title'   => wp_slash($title),
            'post_content' => wp_slash((string) $source->post_content),
            'post_excerpt' => wp_slash((string) $source->post_excerpt),
            'post_parent'  => (int) $source->post_parent,
            'menu_order'   => (int) $source->menu_order,
            'post_author'  => get_current_user_id(),
        ];
        // Drafts get no slug until publish; set one now so the agent can link
        // to the page (wp_unique_post_slug de-duplicates on publish anyway).
        $args['post_name'] = sanitize_title(! empty($input['slug']) ? (string) $input['slug'] : $title);

        $new_id = wp_insert_post($args, true);
        if (is_wp_error($new_id)) {
            return $new_id;
        }
        $new_id = (int) $new_id;

        // Meta (ACF post fields, page template, SEO fields …).
        $meta_copied = 0;
        if (! array_key_exists('include_meta', $input) || ! empty($input['include_meta'])) {
            foreach ((array) get_post_meta($source_id) as $key => $values) {
                $key = (string) $key;
                if (in_array($key, self::SKIP_META, true) || preg_match('/^_yoast_wpseo_.*_score$/', $key)) {
                    continue;
                }
                foreach ((array) $values as $value) {
                    add_post_meta($new_id, $key, wp_slash(maybe_unserialize($value)));
                    $meta_copied++;
                }
            }
        }

        $thumb = (int) get_post_thumbnail_id($source_id);
        if ($thumb > 0) {
            set_post_thumbnail($new_id, $thumb);
        }

        $terms_copied = 0;
        foreach (get_object_taxonomies((string) $source->post_type) as $taxonomy) {
            $terms = wp_get_object_terms($source_id, $taxonomy, ['fields' => 'ids']);
            if (! is_wp_error($terms) && $terms !== []) {
                wp_set_object_terms($new_id, array_map('intval', $terms), $taxonomy);
                $terms_copied += count($terms);
            }
        }

        update_post_meta($new_id, '_ai_by_roadmap_duplicated_from', $source_id);

        clean_post_cache($new_id);
        $new    = get_post($new_id);
        $blocks = parse_blocks((string) ($new ? $new->post_content : ''));

        return [
            'success'        => true,
            'post_id'        => $new_id,
            'source_post_id' => $source_id,
            'title'          => $new ? (string) $new->post_title : $title,
            'slug'           => $new ? (string) $new->post_name : '',
            'status'         => $status,
            'post_type'      => (string) $source->post_type,
            'acf_blocks'     => count(BlockPatcher::acf_positions($blocks)),
            'blocks'         => BlockPatcher::summary($blocks),
            'meta_copied'    => $meta_copied,
            'terms_copied'   => $terms_copied,
            'modified'       => $new ? BlockPatcher::modified($new) : '',
            'preview_url'    => $new ? (string) get_preview_post_link($new) : '',
            'edit_link'      => (string) get_edit_post_link($new_id, 'raw'),
        ];
    }
}
