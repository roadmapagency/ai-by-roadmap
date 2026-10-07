<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Icons;

use Roadmap\AiByRoadmap\Categories;
use Roadmap\AiByRoadmap\Plugin;
use Roadmap\AiByRoadmap\Theme\ThemeSupport;

/**
 * Registers ai-by-roadmap/search-icons for the theme's icon set and hands it
 * to the page- and block-filler agents as a tool.
 *
 * The icon set comes from the theme's `icons` support arg
 * (['provider' => 'lucide'|'font-awesome', …]); filter
 * `ai_by_roadmap_icon_provider` to supply a custom IconProvider.
 */
final class Icons
{
    public const ABILITY = 'ai-by-roadmap/search-icons';

    private static ?IconProvider $provider = null;
    private static bool $resolved = false;

    public static function register(): void
    {
        if (self::provider() === null) {
            return;
        }

        add_action('wp_abilities_api_init', [self::class, 'register_ability']);

        add_filter('ai_by_roadmap_filter_tools_Roadmap\\AiByRoadmap\\Blocks\\Agents\\PageFillerAgent', static function (array $tools): array {
            $tools[] = self::ABILITY;
            return $tools;
        });
        add_filter('ai_by_roadmap_filter_tools_Roadmap\\AiByRoadmap\\Blocks\\Agents\\BlockFillerAgent', static function (array $tools, $agent): array {
            if (str_starts_with($agent->block_id(), 'acf/')) {
                $tools[] = self::ABILITY;
            }
            return $tools;
        }, 10, 2);
    }

    public static function provider(): ?IconProvider
    {
        if (self::$resolved) {
            return self::$provider;
        }
        self::$resolved = true;

        $config   = (array) ThemeSupport::arg('icons', []);
        $provider = null;
        if (($config['provider'] ?? '') === 'lucide' && ! empty($config['svg_dir'])) {
            $provider = new LucideProvider((string) $config['svg_dir'], (string) ($config['tags'] ?? ''));
        } elseif (($config['provider'] ?? '') === 'font-awesome') {
            $provider = new FontAwesomeProvider();
        }

        $provider       = apply_filters('ai_by_roadmap_icon_provider', $provider, $config);
        self::$provider = $provider instanceof IconProvider ? $provider : null;
        return self::$provider;
    }

    /** @return string[] ACF field types that hold icons (skipped by text search/replace). */
    public static function field_types(): array
    {
        return self::provider()?->field_types() ?? [];
    }

    public static function register_ability(): void
    {
        $provider = self::provider();
        if ($provider === null || ! function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability(self::ABILITY, [
            'category'            => Categories::SLUG,
            'label'               => __('Search icons', 'ai-by-roadmap'),
            'description'         => sprintf(
                /* translators: %s: icon set id, e.g. lucide */
                __('Find an icon from the theme\'s icon set (%s) by concept. Search with a visual concept ("shield" for protection, "rocket" for speed) rather than the literal text, and store the returned id in icon fields.', 'ai-by-roadmap'),
                $provider->id()
            ),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['query'],
                'properties'           => [
                    'query' => ['type' => 'string', 'description' => 'A conceptual keyword for the icon (e.g. "shield", "rocket", "calendar clock").'],
                ],
            ],
            'output_schema'       => $provider->output_schema(),
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => static fn(array $input): array => $provider->search((string) ($input['query'] ?? '')),
            'meta'                => Plugin::ability_meta(true),
        ]);
    }
}
