<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Blocks\Links;
use WP_Error;

/**
 * Find every URL stored in ACF block fields and say what is wrong with it:
 * an internal link written with another environment's host, a path that no
 * longer resolves to a post, or nothing. Optionally rewrite the wrong-host
 * ones to site-relative paths, block by block, through the same merge path
 * update-block-fields uses.
 */
final class AuditLinks
{
    public const ID = 'ai-by-roadmap/audit-links';

    private const SCAN_CAP = 200;

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Audit (and fix) links in block fields', 'ai-by-roadmap'),
            'description'         => __('Scan every link stored in ACF blocks — URL fields, ACF link fields and <a href> inside rich text — for one post, one post type, or the whole site, and classify each: internal-ok, internal-wrong-host (points at this site\'s path but with a different host, e.g. a preview or localhost domain), internal-missing (no post at that path), external, special (mailto/tel/#), empty. Read-only by default. Pass fix_hosts: true to rewrite the internal-wrong-host links to site-relative paths (e.g. "/therapy/"); nothing else is changed. Run this after porting content or before a domain change; use resolve-link when writing new links.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => [
                    'post_id'   => ['type' => 'integer', 'description' => 'Audit one post.'],
                    'post_type' => ['type' => 'string', 'description' => 'Audit every post of one type (ignored when post_id is given).'],
                    'kinds'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only report these kinds. Default: internal-wrong-host and internal-missing (the actionable ones). Pass ["*"] for everything.'],
                    'fix_hosts' => ['type' => 'boolean', 'default' => false, 'description' => 'Rewrite internal-wrong-host links to relative paths. Writes to posts.'],
                    'limit'     => ['type' => 'integer', 'description' => 'Max posts to scan (default 100, max 200).'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['posts_scanned', 'links_scanned', 'counts', 'issues', 'fixed'],
                'properties'           => [
                    'posts_scanned' => ['type' => 'integer'],
                    'links_scanned' => ['type' => 'integer'],
                    'home_host'     => ['type' => 'string'],
                    'counts'        => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'Links per kind.'],
                    'issues'        => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['post_id', 'block_index', 'block_type', 'field', 'url', 'kind'],
                            'properties'           => [
                                'post_id'     => ['type' => 'integer'],
                                'post_title'  => ['type' => 'string'],
                                'block_index' => ['type' => 'integer'],
                                'block_type'  => ['type' => 'string'],
                                'field'       => ['type' => 'string', 'description' => 'Field path, e.g. primary_button_url or items[2].link_url.'],
                                'url'         => ['type' => 'string'],
                                'kind'        => ['type' => 'string'],
                                'suggested'   => ['type' => 'string', 'description' => 'The relative path to store instead (wrong-host links).'],
                                'target_post' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'fixed'         => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true], 'description' => 'With fix_hosts: the links rewritten, same shape as issues.'],
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
        $fix   = ! empty($input['fix_hosts']);
        $kinds = isset($input['kinds']) && is_array($input['kinds']) && $input['kinds'] !== []
            ? array_map('strval', $input['kinds'])
            : [Links::INTERNAL_WRONG_HOST, Links::INTERNAL_MISSING];
        $all_kinds = in_array('*', $kinds, true);

        $posts = self::posts_to_scan($input);
        if (is_wp_error($posts)) {
            return $posts;
        }

        $issues        = [];
        $fixed         = [];
        $counts        = [];
        $links_scanned = 0;

        foreach ($posts as $post) {
            if (! current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $blocks    = parse_blocks((string) $post->post_content);
            $positions = BlockPatcher::acf_positions($blocks);
            $dirty     = false;

            foreach ($positions as $index => $raw) {
                $type = (string) $blocks[$raw]['blockName'];
                $defs = AcfBlockFields::definitions($type);
                if ($defs === []) {
                    continue;
                }
                $data    = (array) ($blocks[$raw]['attrs']['data'] ?? []);
                $fields  = AcfBlockFields::unflatten($data, $defs);
                $patch   = $fields;
                $touched = false;
                $changed_tops = [];

                foreach (Links::url_fields($fields, $defs) as $link) {
                    $links_scanned++;
                    $class = Links::classify($link['value']);
                    $counts[$class['kind']] = ($counts[$class['kind']] ?? 0) + 1;

                    $row = [
                        'post_id'     => (int) $post->ID,
                        'post_title'  => (string) $post->post_title,
                        'block_index' => $index,
                        'block_type'  => $type,
                        'field'       => $link['path'] . (! empty($link['inline']) ? ' (inline href)' : ''),
                        'url'         => $link['value'],
                        'kind'        => $class['kind'],
                    ];
                    if ($class['suggested'] !== '') {
                        $row['suggested'] = $class['suggested'];
                    }
                    if ($class['post_id'] > 0) {
                        $row['target_post'] = $class['post_id'];
                    }

                    if ($fix && $class['kind'] === Links::INTERNAL_WRONG_HOST && $class['suggested'] !== '') {
                        if (! empty($link['inline'])) {
                            // href inside rich text: swap just that URL within the HTML.
                            $current = (string) Links::get_at_path($patch, $link['path']);
                            $patch   = Links::set_at_path($patch, $link['path'], str_replace($link['value'], $class['suggested'], $current));
                        } else {
                            $patch = Links::set_at_path($patch, $link['path'], $class['suggested']);
                        }
                        $touched        = true;
                        $changed_tops[] = explode('.', explode('[', $link['path'])[0])[0];
                        $fixed[]        = $row;
                        continue;
                    }

                    if ($all_kinds || in_array($class['kind'], $kinds, true)) {
                        $issues[] = $row;
                    }
                }

                if ($touched) {
                    // Re-flatten only the top-level fields that actually changed
                    // so the rest of the block's stored data is left byte-identical.
                    $patched = BlockPatcher::patch_data($type, $data, $defs, array_intersect_key($patch, array_flip(array_unique($changed_tops))));
                    if ($patched['changed'] !== []) {
                        $blocks[$raw]['attrs']['data'] = $patched['data'];
                        $dirty = true;
                    }
                }
            }

            if ($dirty) {
                $saved = BlockPatcher::save((int) $post->ID, $blocks);
                if (is_wp_error($saved)) {
                    return $saved;
                }
            }
        }

        return [
            'posts_scanned' => count($posts),
            'links_scanned' => $links_scanned,
            'home_host'     => (string) wp_parse_url(home_url(), PHP_URL_HOST),
            'counts'        => $counts ?: new \stdClass(),
            'issues'        => $issues,
            'fixed'         => $fixed,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, \WP_Post>|WP_Error
     */
    private static function posts_to_scan(array $input)
    {
        if (! empty($input['post_id'])) {
            $post = get_post((int) $input['post_id']);
            if (! $post) {
                return new WP_Error('post_not_found', __('Post not found.', 'ai-by-roadmap'), ['status' => 404]);
            }
            return [$post];
        }

        $limit = isset($input['limit']) ? max(1, min(self::SCAN_CAP, (int) $input['limit'])) : 100;
        $types = ! empty($input['post_type'])
            ? [(string) $input['post_type']]
            : array_values(array_diff(array_keys(get_post_types(['public' => true])), ['attachment']));
        $types[] = 'wp_block'; // synced patterns hold links too

        return get_posts([
            'post_type'        => array_unique($types),
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page'   => $limit,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ]);
    }
}
