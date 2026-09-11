<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks;

/**
 * Link classification shared by resolve-link and audit-links.
 *
 * Agents porting content tend to write absolute URLs with whatever host they
 * saw (a Lovable preview, a local clone, a staging domain). Those break the
 * moment the site moves. The rule we want them to follow: internal links are
 * stored as site-relative paths ("/therapy/"), external ones as-is.
 */
final class Links
{
    public const INTERNAL_OK         = 'internal-ok';
    public const INTERNAL_WRONG_HOST = 'internal-wrong-host';
    public const INTERNAL_MISSING    = 'internal-missing';
    public const EXTERNAL            = 'external';
    public const SPECIAL             = 'special'; // mailto:, tel:, #anchor
    public const EMPTY               = 'empty';

    /**
     * Classify a stored URL value.
     *
     * @return array{kind:string, url:string, path:string, host:string, post_id:int, suggested:string}
     */
    public static function classify(string $url): array
    {
        $url  = trim($url);
        $base = ['kind' => self::EMPTY, 'url' => $url, 'path' => '', 'host' => '', 'post_id' => 0, 'suggested' => ''];

        if ($url === '') {
            return $base;
        }
        if (preg_match('/^(mailto:|tel:|sms:|#)/i', $url)) {
            return array_merge($base, ['kind' => self::SPECIAL]);
        }

        $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $parts     = wp_parse_url($url);
        $host      = strtolower((string) ($parts['host'] ?? ''));
        $path      = (string) ($parts['path'] ?? '');
        $query     = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment  = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        // Relative path, or same host.
        if ($host === '' || $host === $home_host) {
            $post_id = self::post_id_for_path($path);
            if ($post_id > 0 || self::is_asset_path($path)) {
                return array_merge($base, ['kind' => self::INTERNAL_OK, 'path' => $path, 'host' => $host, 'post_id' => $post_id, 'suggested' => $host === '' ? '' : $path . $query . $fragment]);
            }
            return array_merge($base, ['kind' => self::INTERNAL_MISSING, 'path' => $path, 'host' => $host]);
        }

        // Different host: does the path exist on THIS site? Then it is an
        // internal link written with a stale host.
        $post_id = self::post_id_for_path($path);
        if ($post_id > 0 || self::is_asset_path($path)) {
            return array_merge($base, ['kind' => self::INTERNAL_WRONG_HOST, 'path' => $path, 'host' => $host, 'post_id' => $post_id, 'suggested' => $path . $query . $fragment]);
        }

        return array_merge($base, ['kind' => self::EXTERNAL, 'path' => $path, 'host' => $host]);
    }

    /**
     * Post ID for a site path such as "/therapy/" or "/programs/iop/", across
     * every public post type (pages, locked CPTs, posts). 0 when nothing matches.
     */
    public static function post_id_for_path(string $path): int
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            $front = (int) get_option('page_on_front');
            return $front > 0 ? $front : 0;
        }

        $id = (int) url_to_postid(home_url($path));
        if ($id > 0) {
            return $id;
        }

        // url_to_postid misses drafts and some CPT rewrites; fall back to the
        // last path segment as a slug across public types.
        $segments = array_values(array_filter(explode('/', $path)));
        $slug     = end($segments);
        if (! is_string($slug) || $slug === '') {
            return 0;
        }
        $types = array_values(array_diff(array_keys(get_post_types(['public' => true])), ['attachment']));
        $found = get_posts([
            'name'           => sanitize_title($slug),
            'post_type'      => $types,
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 2,
            'fields'         => 'ids',
        ]);

        return count($found) === 1 ? (int) $found[0] : 0;
    }

    /**
     * Uploads / theme assets are internal but are not posts.
     */
    private static function is_asset_path(string $path): bool
    {
        return str_starts_with($path, '/wp-content/') || (bool) preg_match('/\.(pdf|jpe?g|png|gif|svg|webp|mp4|css|js)$/i', $path);
    }

    /**
     * The value to store for an internal link: a site-relative path.
     */
    public static function relative(int $post_id): string
    {
        $permalink = (string) get_permalink($post_id);
        return $permalink === '' ? '' : wp_make_link_relative($permalink);
    }

    /**
     * Every URL stored in a block, with the dotted path of the field holding
     * it — walking repeaters and groups via the ACF definitions:
     *
     *   - `url` fields → the value itself.
     *   - `link` fields (JSON {url,title,target}) → path with `.url` suffix.
     *   - rich text / text fields → each `href="…"` inside the HTML, flagged
     *     `inline: true` (the path is the field; the value is the href).
     *
     * @param array<string, mixed>             $fields Unflattened block fields.
     * @param array<int, array<string, mixed>> $defs
     * @return array<int, array{path:string, value:string, inline?:bool}>
     */
    public static function url_fields(array $fields, array $defs, string $prefix = ''): array
    {
        $out = [];
        foreach ($defs as $def) {
            $name = (string) $def['name'];
            if ($name === '' || ! array_key_exists($name, $fields)) {
                continue;
            }
            $value = $fields[$name];
            $path  = $prefix . $name;

            switch ($def['type']) {
                case 'repeater':
                    foreach ((array) $value as $i => $row) {
                        $out = array_merge($out, self::url_fields((array) $row, (array) ($def['sub_fields'] ?? []), "{$path}[{$i}]."));
                    }
                    break;
                case 'group':
                    $out = array_merge($out, self::url_fields((array) $value, (array) ($def['sub_fields'] ?? []), $path . '.'));
                    break;
                case 'url':
                    if (is_string($value)) {
                        $out[] = ['path' => $path, 'value' => $value];
                    }
                    break;
                case 'link':
                    $decoded = is_string($value) && $value !== '' && $value[0] === '{' ? json_decode($value, true) : $value;
                    if (is_array($decoded) && isset($decoded['url']) && is_string($decoded['url'])) {
                        $out[] = ['path' => $path . '.url', 'value' => $decoded['url']];
                    }
                    break;
                case 'wysiwyg':
                case 'textarea':
                case 'text':
                    if (is_string($value) && str_contains($value, 'href') && preg_match_all('/href=(["\'])(.*?)\1/i', $value, $m)) {
                        foreach (array_unique($m[2]) as $href) {
                            $out[] = ['path' => $path, 'value' => (string) $href, 'inline' => true];
                        }
                    }
                    break;
            }
        }
        return $out;
    }

    /**
     * Read the value at a dotted path produced by url_fields().
     *
     * @param array<string, mixed> $fields
     * @return mixed
     */
    public static function get_at_path(array $fields, string $path)
    {
        $tokens = preg_split('/\.(?![^\[]*\])/', $path) ?: [];
        $ref    = $fields;
        foreach ($tokens as $token) {
            if (preg_match('/^(.+)\[(\d+)\]$/', $token, $m)) {
                $ref = $ref[$m[1]][(int) $m[2]] ?? null;
            } else {
                $ref = is_array($ref) ? ($ref[$token] ?? null) : null;
            }
        }
        return $ref;
    }

    /**
     * Write a new value at a dotted path produced by url_fields().
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function set_at_path(array $fields, string $path, string $new_url): array
    {
        $tokens = preg_split('/\.(?![^\[]*\])/', $path) ?: [];
        $ref    = &$fields;
        $last   = array_pop($tokens);

        foreach ($tokens as $token) {
            if (preg_match('/^(.+)\[(\d+)\]$/', $token, $m)) {
                $ref = &$ref[$m[1]][(int) $m[2]];
            } else {
                $ref = &$ref[$token];
            }
        }

        if ($last === 'url' && is_string($ref) && $ref !== '' && $ref[0] === '{') {
            $decoded        = json_decode($ref, true) ?: [];
            $decoded['url'] = $new_url;
            $ref            = $decoded;
        } elseif ($last === 'url' && is_array($ref)) {
            $ref['url'] = $new_url;
        } elseif ($last !== null) {
            $ref[$last] = $new_url;
        }

        return $fields;
    }
}
