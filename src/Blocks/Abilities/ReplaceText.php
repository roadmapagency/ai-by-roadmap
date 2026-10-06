<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Blocks\AcfBlockFields;
use Roadmap\AiByRoadmap\Blocks\BlockPatcher;
use Roadmap\AiByRoadmap\Blocks\Links;
use Roadmap\AiByRoadmap\Blocks\TextFields;
use WP_Error;

/**
 * Bulk find-and-replace across block text fields. Dry-run by default: the
 * first call shows exactly what would change; the second, with
 * dry_run: false, applies it through the same merge path as
 * update-block-fields, one save per post.
 */
final class ReplaceText
{
    public const ID = 'ai-by-roadmap/replace-text';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, true, true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Find and replace text in block fields', 'ai-by-roadmap'),
            'description'         => __('Replace text across ACF block fields on one post, one post type, or the whole site (synced patterns included). Runs as a DRY RUN by default and returns every change it would make (before/after snippets) — review them, then call again with dry_run: false to apply. Matches stored HTML; keep replacements HTML-safe. Regex mode (regex: true) supports $1 back-references in replace. Only text fields are touched (not URLs, images, choices); ai_content is excluded unless include_ai_content is true.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['search', 'replace'],
                'properties'           => [
                    'search'             => ['type' => 'string'],
                    'replace'            => ['type' => 'string'],
                    'regex'              => ['type' => 'boolean', 'default' => false],
                    'case_sensitive'     => ['type' => 'boolean', 'default' => false],
                    'post_id'            => ['type' => 'integer'],
                    'post_type'          => ['type' => 'string'],
                    'include_ai_content' => ['type' => 'boolean', 'default' => false],
                    'dry_run'            => ['type' => 'boolean', 'default' => true, 'description' => 'Default true. Set false to write.'],
                    'limit'              => ['type' => 'integer', 'description' => 'Max posts to scan (default 150, max 300).'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['dry_run', 'posts_scanned', 'posts_affected', 'fields_affected', 'total_replacements', 'changes'],
                'properties'           => [
                    'dry_run'            => ['type' => 'boolean'],
                    'posts_scanned'      => ['type' => 'integer'],
                    'posts_affected'     => ['type' => 'integer'],
                    'fields_affected'    => ['type' => 'integer'],
                    'total_replacements' => ['type' => 'integer'],
                    'changes'            => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['post_id', 'block_index', 'block_type', 'field', 'replacements', 'before', 'after'],
                            'properties'           => [
                                'post_id'      => ['type' => 'integer'],
                                'post_title'   => ['type' => 'string'],
                                'block_index'  => ['type' => 'integer'],
                                'block_type'   => ['type' => 'string'],
                                'field'        => ['type' => 'string'],
                                'replacements' => ['type' => 'integer'],
                                'before'       => ['type' => 'string'],
                                'after'        => ['type' => 'string'],
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
        $dry_run = ! array_key_exists('dry_run', $input) || ! empty($input['dry_run']);
        $replace = (string) $input['replace'];
        $regex   = ! empty($input['regex']);

        $pattern = SearchContent::pattern($input);
        if (is_wp_error($pattern)) {
            return $pattern;
        }

        $posts = SearchContent::posts_to_scan($input, SearchContent::SCAN_CAP, 150);
        if (is_wp_error($posts)) {
            return $posts;
        }

        $changes        = [];
        $posts_affected = 0;
        $total          = 0;

        foreach ($posts as $post) {
            if (! current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $blocks  = parse_blocks((string) $post->post_content);
            $entries = SearchContent::text_fields($post, ! empty($input['include_ai_content']));

            // Group replacements per block so each block is patched once.
            $per_block = [];
            foreach ($entries as $e) {
                $new = $regex
                    ? preg_replace($pattern, $replace, $e['value'], -1, $n)
                    : preg_replace_callback($pattern, static fn() => $replace, $e['value'], -1, $n);
                if (! $n || $new === null || $new === $e['value']) {
                    continue;
                }
                $total += $n;
                preg_match($pattern, $e['value'], $m, PREG_OFFSET_CAPTURE);
                $changes[] = [
                    'post_id'      => (int) $post->ID,
                    'post_title'   => (string) $post->post_title,
                    'block_index'  => $e['block_index'],
                    'block_type'   => $e['block_type'],
                    'field'        => $e['path'],
                    'replacements' => $n,
                    'before'       => TextFields::snippet($e['value'], (int) ($m[0][1] ?? 0), strlen((string) ($m[0][0] ?? ''))),
                    'after'        => TextFields::snippet($new, (int) ($m[0][1] ?? 0), strlen($replace)),
                ];
                $per_block[$e['raw']][] = ['path' => $e['path'], 'value' => $new];
            }

            if ($per_block === []) {
                continue;
            }
            $posts_affected++;

            if ($dry_run) {
                continue;
            }

            foreach ($per_block as $raw => $edits) {
                $type   = (string) $blocks[$raw]['blockName'];
                $defs   = AcfBlockFields::definitions($type);
                $data   = (array) ($blocks[$raw]['attrs']['data'] ?? []);
                $fields = AcfBlockFields::unflatten($data, $defs);
                $top    = [];
                foreach ($edits as $edit) {
                    $fields = Links::set_at_path($fields, $edit['path'], $edit['value']);
                    $top[]  = TextFields::top_level($edit['path']);
                }
                $patched = BlockPatcher::patch_data($type, $data, $defs, array_intersect_key($fields, array_flip(array_unique($top))));
                $blocks[$raw]['attrs']['data'] = $patched['data'];
            }

            $saved = BlockPatcher::save((int) $post->ID, $blocks);
            if (is_wp_error($saved)) {
                return $saved;
            }
        }

        return [
            'dry_run'            => $dry_run,
            'posts_scanned'      => count($posts),
            'posts_affected'     => $posts_affected,
            'fields_affected'    => count($changes),
            'total_replacements' => $total,
            'changes'            => $changes,
        ];
    }
}
