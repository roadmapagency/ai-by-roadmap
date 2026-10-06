<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\Navigation;

/**
 * Everything an agent needs to know about where a page can be linked from:
 * every WordPress menu (by theme location) with its items, and — when the
 * theme stores a megamenu in an ACF options page — its panels and links.
 */
final class GetNavigation
{
    public const ID = 'ai-by-roadmap/get-navigation';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Read the site navigation', 'ai-by-roadmap'),
            'description'         => __('Read the site\'s navigation: every WordPress menu with its theme location and items (title, url, linked post_id, parent), plus the header megamenu when the theme keeps one in an ACF options page (panels with their lead, description and links) and the header CTA. Use it before add-menu-item / update-mega-nav to see where a new page should appear, and after, to confirm.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => new \stdClass(),
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['menus', 'locations', 'mega_nav_supported'],
                'properties'           => [
                    'locations'          => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Theme menu location → its label and the assigned menu (null when unassigned).'],
                    'menus'              => [
                        'type'  => 'array',
                        'items' => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['menu_id', 'name', 'slug', 'locations', 'items'],
                            'properties'           => [
                                'menu_id'   => ['type' => 'integer'],
                                'name'      => ['type' => 'string'],
                                'slug'      => ['type' => 'string'],
                                'locations' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'items'     => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                            ],
                        ],
                    ],
                    'mega_nav_supported' => ['type' => 'boolean', 'description' => 'True when the theme stores a megamenu in the ACF option this plugin knows how to edit.'],
                    'mega_nav_editable'  => ['type' => 'boolean', 'description' => 'False while the option is empty and the theme is rendering built-in defaults; save the Navigation options page once to make it editable.'],
                    'mega_nav'           => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                    'header_cta'         => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed>|null $input Unused; the Abilities API passes null for a no-input tool.
     * @return array<string, mixed>
     */
    public static function execute($input = null): array
    {
        $registered = get_registered_nav_menus();
        $assigned   = get_nav_menu_locations();

        $locations = [];
        foreach ($registered as $slug => $label) {
            $menu_id          = (int) ($assigned[$slug] ?? 0);
            $menu             = $menu_id > 0 ? wp_get_nav_menu_object($menu_id) : null;
            $locations[$slug] = ['label' => (string) $label, 'menu_id' => $menu_id ?: null, 'menu' => $menu ? (string) $menu->name : null];
        }

        $menus = [];
        foreach (wp_get_nav_menus() as $menu) {
            $menus[] = Navigation::describe_menu($menu, $assigned);
        }

        $out = [
            'locations'          => $locations ?: new \stdClass(),
            'menus'              => $menus,
            'mega_nav_supported' => Navigation::mega_nav_supported(),
        ];

        if ($out['mega_nav_supported']) {
            $rows                     = Navigation::mega_nav_rows();
            $out['mega_nav_editable'] = $rows !== [];
            $out['mega_nav']          = Navigation::describe_mega_nav($rows);
            $cta                      = Navigation::header_cta();
            if ($cta) {
                $out['header_cta'] = $cta;
            }
        }

        return $out;
    }
}
