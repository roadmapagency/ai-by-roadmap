<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Theme;

/**
 * Registers every ACF block of the active theme with BlockRegistry via the
 * ai_by_roadmap_register_block filter — the job the theme's
 * roadmap_starter_register_blocks_with_ai() used to do.
 */
final class BlockSchemas
{
    public static function register(): void
    {
        add_filter('ai_by_roadmap_register_block', [self::class, 'add'], 5);
    }

    /**
     * @param  array<int, array<string, mixed>> $registrations
     * @return array<int, array<string, mixed>>
     */
    public static function add(array $registrations): array
    {
        if (! function_exists('acf_get_block_types')) {
            return $registrations;
        }

        $skip = (array) ThemeSupport::arg('skip_blocks', []);
        foreach (array_keys(acf_get_block_types()) as $block_name) {
            if (in_array($block_name, $skip, true)) {
                continue;
            }
            $registrations[] = [
                'block_id' => $block_name,
                'schema'   => SchemaConverter::block($block_name),
            ];
        }

        return $registrations;
    }
}
