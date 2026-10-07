<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Updates the plugin from its GitHub Releases instead of WordPress.org.
 *
 * Each release carries an `ai-by-roadmap.zip` asset (built by
 * .github/workflows/release.yml); the release tag is the version. The repo is
 * public, so no credentials are needed. A token is optional — it lifts GitHub's
 * unauthenticated limit of 60 API requests/hour per server IP, which only
 * matters when many sites share one server:
 *
 *     define('AI_BY_ROADMAP_GITHUB_TOKEN', 'github_pat_…');
 *
 * Disabled when the plugin directory is a git checkout — an update would
 * replace the folder and wipe the working copy. Filter
 * `ai_by_roadmap_enable_updater` to override.
 */
final class Updater
{
    public const REPOSITORY     = 'https://github.com/roadmapagency/ai-by-roadmap/';
    public const TOKEN_CONSTANT = 'AI_BY_ROADMAP_GITHUB_TOKEN';
    private const ASSET_PATTERN = '/^ai-by-roadmap\.zip$/';

    public static function register(): void
    {
        $status = self::status();
        add_filter('plugin_row_meta', static function (array $meta, string $file) use ($status): array {
            if ($file === plugin_basename(PLUGIN_URL_BASE) && $status === 'vcs') {
                $meta[] = esc_html__('Updates: off (git checkout)', 'ai-by-roadmap');
            }
            return $meta;
        }, 10, 2);

        if ($status !== 'enabled') {
            return;
        }

        $checker = PucFactory::buildUpdateChecker(self::REPOSITORY, PLUGIN_URL_BASE, 'ai-by-roadmap');
        $checker->setBranch('main');
        if (self::token() !== '') {
            $checker->setAuthentication(self::token());
        }
        $checker->getVcsApi()->enableReleaseAssets(self::ASSET_PATTERN);
    }

    /** @return 'enabled'|'vcs' */
    private static function status(): string
    {
        $is_vcs = is_dir(PLUGIN_DIR . '/.git');
        return (bool) apply_filters('ai_by_roadmap_enable_updater', ! $is_vcs) ? 'enabled' : 'vcs';
    }

    private static function token(): string
    {
        return defined(self::TOKEN_CONSTANT) ? (string) constant(self::TOKEN_CONSTANT) : '';
    }
}
