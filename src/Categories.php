<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap;

final class Categories
{
    public const SLUG = 'ai-by-roadmap';

    public static function register(): void
    {
        if (! function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(self::SLUG, [
            'label'       => __('AI by Roadmap', 'ai-by-roadmap'),
            'description' => __('Page composition, media search, and content analysis abilities from the AI by Roadmap plugin.', 'ai-by-roadmap'),
        ]);
    }
}
