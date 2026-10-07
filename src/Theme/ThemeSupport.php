<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Theme;

use Roadmap\AiByRoadmap\Icons\Icons;

use const Roadmap\AiByRoadmap\VERSION;

/**
 * The plugin↔theme contract. A theme opts in with:
 *
 *     add_theme_support('ai-by-roadmap', [
 *         'contract'    => 1,          // contract version the theme was written against
 *         'min_plugin'  => '0.4.0',    // oldest plugin version that theme supports
 *         'icons'       => ['provider' => 'lucide', 'index' => '/abs/path/lucide-tags.json'],
 *         'skip_blocks' => ['acf/coverage'],  // ACF blocks to keep out of the AI catalogue
 *     ]);
 *
 * and the plugin then provides the AI glue that used to be copied into every
 * theme fork (AIForGutenbergProvider.php): block schemas, the Source Content
 * field, and icon search.
 *
 * Modes:
 *   - plugin: the theme declared support and the contract matches.
 *   - legacy: an older fork still ships its own provider
 *     (roadmap_starter_register_blocks_with_ai) — the plugin stays out so
 *     nothing registers twice.
 *   - off:    neither, or the contract/version check failed (admin notice).
 *
 * Bump CONTRACTS when hook names, field keys or data shapes change in a way
 * a theme must adapt to.
 */
final class ThemeSupport
{
    public const FEATURE   = 'ai-by-roadmap';
    public const CONTRACTS = [1];

    private static string $mode = 'off';

    /** @var array<string, mixed> */
    private static array $args = [];

    private static ?string $problem = null;

    public static function register(): void
    {
        add_action('after_setup_theme', [self::class, 'boot'], 100);
        add_action('admin_notices', [self::class, 'notice']);
    }

    public static function boot(): void
    {
        self::$mode = self::detect();
        self::$mode = (string) apply_filters('ai_by_roadmap_theme_glue', self::$mode, self::$args);

        if (self::$mode !== 'plugin') {
            return;
        }

        BlockSchemas::register();
        SourceField::register();
        Icons::register();
    }

    public static function mode(): string
    {
        return self::$mode;
    }

    /**
     * @return mixed
     */
    public static function arg(string $key, $default = null)
    {
        return self::$args[$key] ?? $default;
    }

    private static function detect(): string
    {
        if (function_exists('roadmap_starter_register_blocks_with_ai')) {
            return 'legacy';
        }

        $support = get_theme_support(self::FEATURE);
        if ($support === false) {
            return 'off';
        }

        self::$args = is_array($support) && isset($support[0]) && is_array($support[0]) ? $support[0] : [];

        $contract = (int) (self::$args['contract'] ?? 1);
        if (! in_array($contract, self::CONTRACTS, true)) {
            self::$problem = sprintf(
                /* translators: 1: theme contract, 2: supported contracts */
                __('The active theme targets AI by Roadmap contract %1$d, but this plugin version supports %2$s. Update whichever is older.', 'ai-by-roadmap'),
                $contract,
                implode(', ', self::CONTRACTS)
            );
            return 'off';
        }

        $min = (string) (self::$args['min_plugin'] ?? '0');
        if (version_compare(VERSION, $min, '<')) {
            self::$problem = sprintf(
                /* translators: 1: required version, 2: installed version */
                __('The active theme needs AI by Roadmap %1$s or newer (installed: %2$s). Update the plugin.', 'ai-by-roadmap'),
                $min,
                VERSION
            );
            return 'off';
        }

        return 'plugin';
    }

    public static function notice(): void
    {
        if (self::$problem === null || ! current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>AI by Roadmap:</strong> ' . esc_html(self::$problem) . '</p></div>';
    }
}
