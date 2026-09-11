<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\Navigation;
use WP_Error;

/**
 * Edit one panel of the header megamenu stored in the theme's ACF options
 * page: add or remove a link, change the lead/description/footer link, or
 * retitle/relink the top-level item. Refuses cleanly when the active theme
 * does not keep its megamenu that way.
 */
final class UpdateMegaNav
{
    public const ID = 'ai-by-roadmap/update-mega-nav';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, false),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Update a header megamenu panel', 'ai-by-roadmap'),
            'description'         => __('Edit one panel of the header megamenu (get-navigation → mega_nav): add_link {title?, url | post_id, description?, position?}, remove_link {title | url}, set {lead?, description?, footer_link: {title, url | post_id} | null}, and/or item {title?, url | post_id} to retitle or relink the top-level entry. Identify the panel by its top-level title (e.g. "Therapy") or zero-based index. Links to posts are stored as relative paths. Only works when the theme stores its megamenu in the ACF option this plugin knows (mega_nav_supported in get-navigation) and the option has been saved once (mega_nav_editable).', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['panel'],
                'properties'           => [
                    'panel'       => ['type' => ['string', 'integer'], 'description' => 'Panel title (case-insensitive) or zero-based index.'],
                    'add_link'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'title'       => ['type' => 'string'],
                            'url'         => ['type' => 'string'],
                            'post_id'     => ['type' => 'integer'],
                            'description' => ['type' => 'string', 'description' => 'One-line description shown under the label.'],
                            'position'    => ['type' => 'integer', 'description' => '1-based position in the panel. Default: last.'],
                            'new_tab'     => ['type' => 'boolean'],
                        ],
                    ],
                    'remove_link' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'title' => ['type' => 'string'],
                            'url'   => ['type' => 'string'],
                        ],
                    ],
                    'set'         => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'lead'        => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'footer_link' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => '{title, url | post_id}, or null to remove.'],
                        ],
                    ],
                    'item'        => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'title'   => ['type' => 'string'],
                            'url'     => ['type' => 'string'],
                            'post_id' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['success', 'panel_index', 'panel', 'changed'],
                'properties'           => [
                    'success'     => ['type' => 'boolean'],
                    'panel_index' => ['type' => 'integer'],
                    'changed'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'panel'       => ['type' => 'object', 'additionalProperties' => true, 'description' => 'The panel after the change, in get-navigation shape.'],
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
        if (! Navigation::mega_nav_supported()) {
            return new WP_Error('not_supported', __('The active theme does not store a megamenu in an ACF option this plugin can edit. Edit the header navigation in the theme\'s own settings.', 'ai-by-roadmap'), ['status' => 501]);
        }
        $rows = Navigation::mega_nav_rows();
        if ($rows === []) {
            return new WP_Error('mega_nav_not_editable', __('The megamenu option is empty — the theme is rendering its built-in defaults. Open the Navigation & Footer options page in wp-admin and save it once; the defaults are then stored and become editable here.', 'ai-by-roadmap'), ['status' => 409]);
        }

        $index = Navigation::find_panel($rows, $input['panel']);
        if ($index === null) {
            $titles = array_map(static fn($r) => Navigation::link($r['item'] ?? null)['title'] ?? '?', $rows);
            return new WP_Error('panel_not_found', sprintf(
                /* translators: 1: requested panel, 2: available panel titles */
                __('No megamenu panel "%1$s". Panels: %2$s.', 'ai-by-roadmap'),
                (string) $input['panel'],
                implode(', ', $titles)
            ), ['status' => 404]);
        }

        $row     = $rows[$index];
        $changed = [];

        if (! empty($input['item']) && is_array($input['item'])) {
            $current = Navigation::link($row['item'] ?? null) ?: ['title' => '', 'url' => '', 'target' => ''];
            $spec    = $input['item'] + ['title' => $current['title']];
            if (empty($spec['url']) && empty($spec['post_id'])) {
                $spec['url'] = $current['url'];
            }
            $link = Navigation::link_from_spec($spec, __('Panel item', 'ai-by-roadmap'));
            if (is_wp_error($link)) {
                return $link;
            }
            $row['item'] = $link;
            $changed[]   = 'item';
        }

        if (! empty($input['set']) && is_array($input['set'])) {
            foreach (['lead', 'description'] as $key) {
                if (array_key_exists($key, $input['set'])) {
                    $row[$key] = (string) $input['set'][$key];
                    $changed[] = $key;
                }
            }
            if (array_key_exists('footer_link', $input['set'])) {
                if ($input['set']['footer_link'] === null || $input['set']['footer_link'] === []) {
                    $row['footer_link'] = '';
                } else {
                    $link = Navigation::link_from_spec((array) $input['set']['footer_link'], __('footer_link', 'ai-by-roadmap'));
                    if (is_wp_error($link)) {
                        return $link;
                    }
                    $row['footer_link'] = $link;
                }
                $changed[] = 'footer_link';
            }
        }

        $links = array_values((array) ($row['links'] ?? []));

        if (! empty($input['remove_link']) && is_array($input['remove_link'])) {
            $t      = mb_strtolower(trim((string) ($input['remove_link']['title'] ?? '')));
            $u      = trim((string) ($input['remove_link']['url'] ?? ''));
            $before = count($links);
            $links  = array_values(array_filter($links, static function ($l) use ($t, $u): bool {
                $lk = Navigation::link($l['link'] ?? null);
                if (! $lk) {
                    return true;
                }
                if ($t !== '' && mb_strtolower($lk['title']) === $t) {
                    return false;
                }
                return ! ($u !== '' && untrailingslashit($lk['url']) === untrailingslashit($u));
            }));
            if (count($links) === $before) {
                return new WP_Error('link_not_found', __('remove_link matched no link in that panel (match is on exact title or url).', 'ai-by-roadmap'), ['status' => 404]);
            }
            $changed[] = 'remove_link';
        }

        if (! empty($input['add_link']) && is_array($input['add_link'])) {
            $link = Navigation::link_from_spec($input['add_link'], __('add_link', 'ai-by-roadmap'));
            if (is_wp_error($link)) {
                return $link;
            }
            $entry = ['link' => $link, 'description' => (string) ($input['add_link']['description'] ?? '')];
            $pos   = isset($input['add_link']['position']) ? max(0, (int) $input['add_link']['position'] - 1) : count($links);
            array_splice($links, min($pos, count($links)), 0, [$entry]);
            $changed[] = 'add_link';
        }

        if ($changed === []) {
            return new WP_Error('nothing_to_update', __('Supply at least one of add_link, remove_link, set or item.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $row['links'] = $links;
        $rows[$index] = $row;

        if (! Navigation::save_mega_nav_rows($rows)) {
            return new WP_Error('save_failed', __('ACF did not save the megamenu option.', 'ai-by-roadmap'), ['status' => 500]);
        }

        $fresh = Navigation::describe_mega_nav(Navigation::mega_nav_rows());

        return [
            'success'     => true,
            'panel_index' => $index,
            'changed'     => array_values(array_unique($changed)),
            'panel'       => $fresh[$index] ?? new \stdClass(),
        ];
    }
}
