<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Theme;

/**
 * Appends the "Source Content" textarea (`ai_content`) to every ACF block's
 * field group — the verbatim source copy a block was filled from, which the
 * block swapper and the fill abilities read.
 *
 * Key and position match what roadmap-starter's AbstractBlock used to add
 * itself (`field_{block}_ai_content`, last), so content saved under the old
 * theme keeps binding. Groups that already have the field are left alone.
 */
final class SourceField
{
    public const NAME = 'ai_content';

    public static function register(): void
    {
        // Block field groups are added on acf/init priority 20.
        add_action('acf/init', [self::class, 'inject'], 25);
    }

    public static function inject(): void
    {
        if (! function_exists('acf_get_block_types')) {
            return;
        }

        foreach (array_keys(acf_get_block_types()) as $block_name) {
            foreach (acf_get_field_groups(['block' => $block_name]) as $group) {
                $key = (string) $group['key'];
                if (! str_starts_with($key, 'group_') || ! acf_is_local_field_group($key)) {
                    continue;
                }

                $fields = (array) acf_get_fields($key);
                if (in_array(self::NAME, array_column($fields, 'name'), true)) {
                    continue;
                }

                acf_add_local_field([
                    'key'          => 'field_' . substr($key, 6) . '_' . self::NAME,
                    'label'        => __('Source Content', 'ai-by-roadmap'),
                    'name'         => self::NAME,
                    'type'         => 'textarea',
                    'instructions' => __('The original copy this block was built from. Used when switching this block to a different block type — it does not appear on the page.', 'ai-by-roadmap'),
                    'rows'         => 4,
                    'parent'       => $key,
                    'menu_order'   => count($fields),
                ]);
            }
        }
    }
}
