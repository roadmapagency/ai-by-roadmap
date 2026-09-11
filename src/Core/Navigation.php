<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core;

use Roadmap\AiByRoadmap\Blocks\Links;

/**
 * Navigation helpers shared by get-navigation, add-menu-item and
 * update-mega-nav. WordPress menus are generic; the megamenu is theme
 * territory — roadmap-starter forks keep it in an ACF options page as a
 * `mega_nav` repeater (rows: item link, lead, description, links[] of
 * {link, description}, footer_link). The field name is filterable so another
 * theme can point the plugin at its own structure of the same shape.
 */
final class Navigation
{
    public static function mega_nav_field(): string
    {
        /**
         * Filters the ACF options field that holds the megamenu repeater.
         *
         * @param string $field Field name. Default 'mega_nav'.
         */
        return (string) apply_filters('ai_by_roadmap_mega_nav_field', 'mega_nav');
    }

    public static function mega_nav_supported(): bool
    {
        if (! function_exists('get_field_object')) {
            return false;
        }
        $obj = get_field_object(self::mega_nav_field(), 'option');
        return is_array($obj) && ($obj['type'] ?? '') === 'repeater';
    }

    /**
     * Raw option rows (ACF return formats: link fields as arrays).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mega_nav_rows(): array
    {
        $rows = function_exists('get_field') ? get_field(self::mega_nav_field(), 'option') : null;
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Persist the rows and confirm by re-reading. ACF's update_field() on a
     * repeater returns update_option()'s result for the ROW COUNT, which is
     * false whenever the number of panels did not change — even though the
     * sub-rows were written — so its return value cannot be trusted here.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return bool
     */
    public static function save_mega_nav_rows(array $rows): bool
    {
        if (! function_exists('update_field')) {
            return false;
        }
        $rows = array_values($rows);
        update_field(self::mega_nav_field(), $rows, 'option');

        // ACF caches option values per request; drop them before re-reading.
        if (function_exists('acf_flush_value_cache')) {
            acf_flush_value_cache('option', self::mega_nav_field());
        }
        wp_cache_flush_group('acf');

        return self::describe_mega_nav(self::mega_nav_rows()) == self::describe_mega_nav($rows);
    }

    /**
     * @return array<string, string>|null
     */
    public static function header_cta(): ?array
    {
        $cta = function_exists('get_field') ? get_field('header_cta', 'option') : null;
        return is_array($cta) && ! empty($cta['url']) ? self::link($cta) : null;
    }

    /**
     * Normalise an ACF link value to {title, url, target}.
     *
     * @param mixed $value
     * @return array{title:string, url:string, target:string}|null
     */
    public static function link($value): ?array
    {
        if (is_string($value) && $value !== '' && $value[0] === '{') {
            $value = json_decode($value, true);
        }
        if (! is_array($value) || empty($value['url'])) {
            return null;
        }
        return [
            'title'  => (string) ($value['title'] ?? ''),
            'url'    => (string) $value['url'],
            'target' => (string) ($value['target'] ?? ''),
        ];
    }

    /**
     * Build an ACF link value from an agent's {title, url | post_id}.
     *
     * @param array<string, mixed> $spec
     * @return array{title:string, url:string, target:string}|\WP_Error
     */
    public static function link_from_spec(array $spec, string $what)
    {
        $post_id = (int) ($spec['post_id'] ?? 0);
        $url     = trim((string) ($spec['url'] ?? ''));
        $title   = trim((string) ($spec['title'] ?? ''));

        if ($post_id > 0) {
            $post = get_post($post_id);
            if (! $post) {
                return new \WP_Error('post_not_found', sprintf(
                    /* translators: 1: what is being linked, 2: post id */
                    __('%1$s: post %2$d not found.', 'ai-by-roadmap'),
                    $what,
                    $post_id
                ), ['status' => 404]);
            }
            $url   = Links::relative($post_id);
            $title = $title !== '' ? $title : (string) $post->post_title;
        }
        if ($url === '') {
            return new \WP_Error('missing_url', sprintf(
                /* translators: %s: what is being linked */
                __('%s needs a url or a post_id.', 'ai-by-roadmap'),
                $what
            ), ['status' => 400]);
        }
        if ($title === '') {
            return new \WP_Error('missing_title', sprintf(
                /* translators: %s: what is being linked */
                __('%s needs a title when linking by url.', 'ai-by-roadmap'),
                $what
            ), ['status' => 400]);
        }

        return ['title' => $title, 'url' => $url, 'target' => ! empty($spec['new_tab']) ? '_blank' : ''];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function describe_mega_nav(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $links = [];
            foreach ((array) ($row['links'] ?? []) as $l) {
                $lk = self::link($l['link'] ?? null);
                if ($lk) {
                    $links[] = $lk + ['description' => (string) ($l['description'] ?? '')];
                }
            }
            $out[] = [
                'index'       => (int) $i,
                'item'        => self::link($row['item'] ?? null),
                'lead'        => (string) ($row['lead'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'links'       => $links,
                'footer_link' => self::link($row['footer_link'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Find a panel by zero-based index or by its item title (case-insensitive).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int|null
     */
    public static function find_panel(array $rows, $panel): ?int
    {
        if (is_int($panel) || (is_string($panel) && ctype_digit($panel))) {
            $i = (int) $panel;
            return isset($rows[$i]) ? $i : null;
        }
        $needle = mb_strtolower(trim((string) $panel));
        foreach ($rows as $i => $row) {
            $item = self::link($row['item'] ?? null);
            if ($item && mb_strtolower($item['title']) === $needle) {
                return (int) $i;
            }
        }
        return null;
    }

    /**
     * @param array<string, int> $assigned location → menu id
     * @return array<string, mixed>
     */
    public static function describe_menu(\WP_Term $menu, array $assigned): array
    {
        $items = [];
        foreach ((array) wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]) as $item) {
            $items[] = [
                'item_id'   => (int) $item->ID,
                'title'     => (string) $item->title,
                'url'       => (string) $item->url,
                'type'      => (string) $item->type,
                'object'    => (string) $item->object,
                'post_id'   => $item->type === 'post_type' ? (int) $item->object_id : 0,
                'parent_id' => (int) $item->menu_item_parent,
                'order'     => (int) $item->menu_order,
            ];
        }
        return [
            'menu_id'   => (int) $menu->term_id,
            'name'      => (string) $menu->name,
            'slug'      => (string) $menu->slug,
            'locations' => array_values(array_keys(array_filter($assigned, static fn($id) => (int) $id === (int) $menu->term_id))),
            'items'     => $items,
        ];
    }

    /**
     * Resolve a menu by location slug, menu slug, name or id.
     *
     * @return \WP_Term|\WP_Error
     */
    public static function resolve_menu(string $ref)
    {
        $assigned = get_nav_menu_locations();
        if (isset($assigned[$ref]) && (int) $assigned[$ref] > 0) {
            $menu = wp_get_nav_menu_object((int) $assigned[$ref]);
            if ($menu) {
                return $menu;
            }
        }
        $menu = wp_get_nav_menu_object(ctype_digit($ref) ? (int) $ref : $ref);
        if ($menu) {
            return $menu;
        }
        $known = array_map(static fn($m) => $m->slug, wp_get_nav_menus());
        return new \WP_Error('menu_not_found', sprintf(
            /* translators: 1: requested menu, 2: menu slugs, 3: location slugs */
            __('No menu "%1$s". Menus: %2$s. Locations: %3$s.', 'ai-by-roadmap'),
            $ref,
            $known ? implode(', ', $known) : __('(none)', 'ai-by-roadmap'),
            implode(', ', array_keys(get_registered_nav_menus()))
        ), ['status' => 404]);
    }
}
