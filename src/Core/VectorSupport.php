<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core;

/**
 * Feature-detects whether the host database supports the MariaDB native
 * VECTOR column type (shipped in 11.7+). When unavailable, callers must
 * degrade to keyword-based search and skip embedding generation.
 *
 * Probes `VEC_FromText('[0]')` rather than parsing `SELECT VERSION()` so the
 * check survives MariaDB forks and reflects actual capability, not branding.
 */
final class VectorSupport
{
    private const CACHE_KEY   = 'ai_by_roadmap_vector_support';
    private const CACHE_GROUP = 'ai_by_roadmap';

    public static function is_available(): bool
    {
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if ($cached !== false) {
            return (bool) $cached;
        }

        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $result   = $wpdb->get_var("SELECT VEC_FromText('[0]')");
        $wpdb->suppress_errors($suppress);

        $supported = ($result !== null) && empty($wpdb->last_error);

        wp_cache_set(self::CACHE_KEY, $supported ? 1 : 0, self::CACHE_GROUP, DAY_IN_SECONDS);
        return $supported;
    }

    public static function flush_cache(): void
    {
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }
}
