<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\Navigation;
use WP_Error;

/**
 * Add (or remove) an item in a WordPress menu — the last step of creating a
 * page that should be reachable from the footer or any other menu location.
 */
final class AddMenuItem
{
    public const ID = 'ai-by-roadmap/add-menu-item';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, false),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Add or remove a menu item', 'ai-by-roadmap'),
            'description'         => __('Add an item to a WordPress menu, identified by theme location (e.g. "footer-col-1"), menu slug or name — link a post by post_id (title defaults to the post title) or a custom url with a title; optional parent_item_id for nesting and position (1-based; default append). Pass remove_item_id instead to delete an item. Call get-navigation first to see menus, locations and existing items; for the header megamenu use update-mega-nav.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['menu'],
                'properties'           => [
                    'menu'           => ['type' => 'string', 'description' => 'Theme location slug, menu slug, menu name or menu id.'],
                    'post_id'        => ['type' => 'integer', 'description' => 'Post to link (type post_type).'],
                    'url'            => ['type' => 'string', 'description' => 'Custom URL to link (type custom). Use a relative path for internal links.'],
                    'title'          => ['type' => 'string', 'description' => 'Item label. Required with url; defaults to the post title with post_id.'],
                    'parent_item_id' => ['type' => 'integer', 'description' => 'Existing item_id to nest under.'],
                    'position'       => ['type' => 'integer', 'description' => '1-based position among siblings. Default: last.'],
                    'new_tab'        => ['type' => 'boolean', 'default' => false],
                    'remove_item_id' => ['type' => 'integer', 'description' => 'Remove this item instead of adding one.'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'menu_id', 'menu', 'items'],
                'properties'           => [
                    'success' => ['type' => 'boolean'],
                    'menu_id' => ['type' => 'integer'],
                    'menu'    => ['type' => 'string'],
                    'item_id' => ['type' => 'integer', 'description' => 'The added item.'],
                    'removed' => ['type' => 'integer'],
                    'items'   => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_theme_options'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function execute(array $input)
    {
        $menu = Navigation::resolve_menu(trim((string) $input['menu']));
        if (is_wp_error($menu)) {
            return $menu;
        }
        $menu_id = (int) $menu->term_id;

        if (! empty($input['remove_item_id'])) {
            $item_id = (int) $input['remove_item_id'];
            $item    = get_post($item_id);
            if (! $item || $item->post_type !== 'nav_menu_item' || ! is_object_in_term($item_id, 'nav_menu', $menu_id)) {
                return new WP_Error('item_not_found', sprintf(
                    /* translators: 1: item id, 2: menu name */
                    __('Menu item %1$d is not in menu "%2$s".', 'ai-by-roadmap'),
                    $item_id,
                    (string) $menu->name
                ), ['status' => 404]);
            }
            if (! wp_delete_post($item_id, true)) {
                return new WP_Error('remove_failed', __('Could not remove the menu item.', 'ai-by-roadmap'), ['status' => 500]);
            }
            return self::result($menu, ['removed' => $item_id]);
        }

        $link = Navigation::link_from_spec($input, __('Menu item', 'ai-by-roadmap'));
        if (is_wp_error($link)) {
            return $link;
        }

        $args = [
            'menu-item-title'     => $link['title'],
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => (int) ($input['parent_item_id'] ?? 0),
            'menu-item-target'    => $link['target'],
        ];
        if (! empty($input['post_id'])) {
            $post = get_post((int) $input['post_id']);
            $args += [
                'menu-item-type'      => 'post_type',
                'menu-item-object'    => (string) $post->post_type,
                'menu-item-object-id' => (int) $post->ID,
            ];
        } else {
            $args += ['menu-item-type' => 'custom', 'menu-item-url' => $link['url']];
        }

        if (! empty($input['position'])) {
            $args['menu-item-position'] = max(1, (int) $input['position']);
        } else {
            $args['menu-item-position'] = count((array) wp_get_nav_menu_items($menu_id)) + 1;
        }

        $item_id = wp_update_nav_menu_item($menu_id, 0, $args);
        if (is_wp_error($item_id)) {
            return $item_id;
        }

        return self::result($menu, ['item_id' => (int) $item_id]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function result(\WP_Term $menu, array $extra): array
    {
        wp_cache_delete((int) $menu->term_id, 'nav_menu');
        $described = Navigation::describe_menu($menu, get_nav_menu_locations());
        return $extra + [
            'success' => true,
            'menu_id' => (int) $menu->term_id,
            'menu'    => (string) $menu->name,
            'items'   => $described['items'],
        ];
    }
}
