<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core;

use Roadmap\AiByRoadmap\Blocks\Links;
use WP_Error;
use WP_Post;

/**
 * Per-post SEO data via Yoast SEO's own storage API (WPSEO_Meta) and output
 * surface (YoastSEO()->meta), so what we read and write is exactly what the
 * Yoast sidebar shows and the front end renders.
 *
 * Field names deliberately mirror Yoast's forthcoming
 * yoast-seo/get-post-seo-data / update-post-seo-data abilities, so an agent
 * skill written against ours keeps working when those ship (they are bridged
 * into our MCP server as soon as Yoast registers them).
 */
final class Seo
{
    /** Google truncates around these widths; treat as soft limits. */
    public const TITLE_MAX       = 60;
    public const DESCRIPTION_MIN = 70;
    public const DESCRIPTION_MAX = 156;

    /**
     * Our field name → Yoast meta key (without the `_yoast_wpseo_` prefix).
     * `noindex`/`nofollow`/`is_cornerstone` are booleans mapped below.
     */
    public const FIELDS = [
        'seo_title'              => 'title',
        'meta_description'       => 'metadesc',
        'focus_keyphrase'        => 'focuskw',
        'canonical'              => 'canonical',
        'noindex'                => 'meta-robots-noindex',
        'nofollow'               => 'meta-robots-nofollow',
        'breadcrumb_title'       => 'bctitle',
        'is_cornerstone'         => 'is_cornerstone',
        'open_graph_title'       => 'opengraph-title',
        'open_graph_description' => 'opengraph-description',
        'open_graph_image'       => 'opengraph-image-id',
        'twitter_title'          => 'twitter-title',
        'twitter_description'    => 'twitter-description',
        'twitter_image'          => 'twitter-image-id',
    ];

    public static function available(): bool
    {
        return class_exists('\WPSEO_Meta') && function_exists('YoastSEO');
    }

    public static function unavailable_error(): WP_Error
    {
        return new WP_Error('yoast_not_active', __('Yoast SEO is not active on this site; the SEO tools need it.', 'ai-by-roadmap'), ['status' => 501]);
    }

    /**
     * Resolve a post from post_id or a permalink / path.
     *
     * @param array<string, mixed> $input
     * @return WP_Post|WP_Error
     */
    public static function resolve_post(array $input)
    {
        $post_id = (int) ($input['post_id'] ?? 0);
        if ($post_id <= 0 && ! empty($input['permalink'])) {
            $path    = (string) (wp_parse_url((string) $input['permalink'], PHP_URL_PATH) ?? '');
            $post_id = Links::post_id_for_path($path !== '' ? $path : (string) $input['permalink']);
        }
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (! $post) {
            return new WP_Error('post_not_found', __('Post not found. Pass post_id, or a permalink/path that resolves to a post on this site (see resolve-link).', 'ai-by-roadmap'), ['status' => 404]);
        }
        if (! current_user_can('edit_post', (int) $post->ID)) {
            return new WP_Error('forbidden', __('You are not allowed to edit this post.', 'ai-by-roadmap'), ['status' => 403]);
        }
        return $post;
    }

    /**
     * Stored (raw) values, in our field names.
     *
     * @return array<string, mixed>
     */
    public static function stored(int $post_id): array
    {
        $out = [];
        foreach (self::FIELDS as $name => $key) {
            $raw = (string) \WPSEO_Meta::get_value($key, $post_id);
            switch ($name) {
                case 'noindex':
                    $out[$name] = $raw === '1' ? true : ($raw === '2' ? false : null); // null = post-type default
                    break;
                case 'nofollow':
                case 'is_cornerstone':
                    $out[$name] = $raw === '1';
                    break;
                case 'open_graph_image':
                case 'twitter_image':
                    $out[$name] = is_numeric($raw) ? (int) $raw : 0;
                    break;
                default:
                    $out[$name] = $raw;
            }
        }
        return $out;
    }

    /**
     * What the front end will actually output (templates applied, fallbacks
     * resolved), plus Yoast's stored analysis scores.
     *
     * @return array<string, mixed>
     */
    public static function rendered(WP_Post $post): array
    {
        $meta        = YoastSEO()->meta->for_post((int) $post->ID);
        $title       = $meta ? (string) $meta->title : '';
        $description = $meta ? (string) $meta->description : '';
        $robots      = $meta ? (array) $meta->robots : [];

        return [
            'title'             => $title,
            'title_chars'       => mb_strlen($title),
            'description'       => $description,
            'description_chars' => mb_strlen($description),
            'canonical'         => $meta ? (string) $meta->canonical : '',
            'robots'            => implode(', ', array_filter(array_map('strval', $robots))),
            'indexable'         => ($robots['index'] ?? 'index') !== 'noindex',
        ];
    }

    /**
     * @return array{seo_score:int, readability_score:int}
     */
    public static function scores(int $post_id): array
    {
        return [
            'seo_score'         => (int) get_post_meta($post_id, '_yoast_wpseo_linkdex', true),
            'readability_score' => (int) get_post_meta($post_id, '_yoast_wpseo_content_score', true),
        ];
    }

    /**
     * Human-readable problems with the current SEO data.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $rendered
     * @return array<int, string>
     */
    public static function issues(array $stored, array $rendered): array
    {
        $issues = [];
        if (trim((string) $stored['meta_description']) === '') {
            $issues[] = 'missing_description';
        } elseif ($rendered['description_chars'] > self::DESCRIPTION_MAX) {
            $issues[] = 'description_too_long';
        } elseif ($rendered['description_chars'] < self::DESCRIPTION_MIN) {
            $issues[] = 'description_too_short';
        }
        if ($rendered['title_chars'] > self::TITLE_MAX) {
            $issues[] = 'title_too_long';
        }
        if (trim((string) $stored['focus_keyphrase']) === '') {
            $issues[] = 'missing_focus_keyphrase';
        }
        if (! $rendered['indexable']) {
            $issues[] = 'noindex';
        }
        return $issues;
    }

    /**
     * Full report for one post.
     *
     * @return array<string, mixed>
     */
    public static function report(WP_Post $post): array
    {
        $stored   = self::stored((int) $post->ID);
        $rendered = self::rendered($post);

        return [
            'post_id'    => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'post_type'  => (string) $post->post_type,
            'status'     => (string) $post->post_status,
            'permalink'  => (string) get_permalink($post),
            'fields'     => $stored,
            'rendered'   => $rendered,
            'scores'     => self::scores((int) $post->ID),
            'issues'     => self::issues($stored, $rendered),
            'edit_link'  => (string) get_edit_post_link((int) $post->ID, 'raw'),
        ];
    }

    /**
     * Write fields (our names) through WPSEO_Meta::set_value so Yoast's
     * sanitisation and indexable updates run. Returns the names that changed.
     *
     * @param array<string, mixed> $fields
     * @return array<int, string>|WP_Error
     */
    public static function write(int $post_id, array $fields)
    {
        $before  = self::stored($post_id);
        $changed = [];

        foreach ($fields as $name => $value) {
            $name = (string) $name;
            if (! isset(self::FIELDS[$name])) {
                return new WP_Error('invalid_field', sprintf(
                    /* translators: 1: field name, 2: valid field names */
                    __('"%1$s" is not an SEO field. Valid fields: %2$s.', 'ai-by-roadmap'),
                    $name,
                    implode(', ', array_keys(self::FIELDS))
                ), ['status' => 400]);
            }
            $key = self::FIELDS[$name];

            switch ($name) {
                case 'noindex':
                    // null → post-type default, true → noindex, false → index.
                    $raw = $value === null || $value === '' ? '0' : (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '2');
                    break;
                case 'nofollow':
                case 'is_cornerstone':
                    $raw = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
                    break;
                case 'open_graph_image':
                case 'twitter_image':
                    $id = (int) $value;
                    if ($id > 0 && ! wp_attachment_is_image($id)) {
                        return new WP_Error('invalid_attachment', sprintf(
                            /* translators: %s: field name */
                            __('%s must be an image attachment ID (or 0 to clear).', 'ai-by-roadmap'),
                            $name
                        ), ['status' => 400]);
                    }
                    $raw = $id > 0 ? (string) $id : '';
                    // Yoast keeps the URL alongside the ID.
                    \WPSEO_Meta::set_value(str_replace('-id', '', $key), $id > 0 ? (string) wp_get_attachment_url($id) : '', $post_id);
                    break;
                case 'canonical':
                    $raw = trim((string) $value);
                    if ($raw !== '' && ! wp_http_validate_url($raw) && ! str_starts_with($raw, '/')) {
                        return new WP_Error('invalid_canonical', __('canonical must be an absolute URL, a site-relative path, or "" to clear.', 'ai-by-roadmap'), ['status' => 400]);
                    }
                    if (str_starts_with($raw, '/')) {
                        $raw = home_url($raw);
                    }
                    break;
                default:
                    if (is_array($value) || is_object($value)) {
                        return new WP_Error('invalid_field_value', sprintf(
                            /* translators: %s: field name */
                            __('%s must be a string.', 'ai-by-roadmap'),
                            $name
                        ), ['status' => 400]);
                    }
                    $raw = trim((string) $value);
            }

            \WPSEO_Meta::set_value($key, $raw, $post_id);
        }

        $after = self::stored($post_id);
        foreach (array_keys($fields) as $name) {
            if (($before[$name] ?? null) !== ($after[$name] ?? null)) {
                $changed[] = (string) $name;
            }
        }
        if ($changed !== []) {
            self::refresh_indexable($post_id);
        }
        return $changed;
    }

    /**
     * Rebuild Yoast's indexable for the post now. Yoast's own meta watcher
     * defers the rebuild to shutdown, so without this a write followed by a
     * read in the same request (exactly what an agent does) reports the old
     * title/description/robots.
     */
    public static function refresh_indexable(int $post_id): void
    {
        try {
            $container = YoastSEO()->classes;
            $repo      = $container->get(\Yoast\WP\SEO\Repositories\Indexable_Repository::class);
            $builder   = $container->get(\Yoast\WP\SEO\Builders\Indexable_Builder::class);
            $existing  = $repo->find_by_id_and_type($post_id, 'post', false);
            $built     = $builder->build_for_id_and_type($post_id, 'post', $existing ?: false);

            // The meta surface memoizes the rendered context per indexable for
            // the rest of the request; drop it so the next for_post() is fresh.
            $memoizer = $container->get(\Yoast\WP\SEO\Memoizers\Meta_Tags_Context_Memoizer::class);
            foreach (array_filter([$existing ?: null, is_object($built) ? $built : null]) as $indexable) {
                if (method_exists($memoizer, 'clear')) {
                    $memoizer->clear($indexable);
                }
            }
        } catch (\Throwable $e) {
            // Best effort: the shutdown watcher will still rebuild it.
        }
    }

    /**
     * Soft warnings about lengths after a write (not errors — the agent decides).
     *
     * @param array<string, mixed> $rendered
     * @return array<int, string>
     */
    public static function warnings(array $rendered): array
    {
        $w = [];
        if ($rendered['title_chars'] > self::TITLE_MAX) {
            $w[] = sprintf('Rendered title is %d characters; Google shows about %d.', $rendered['title_chars'], self::TITLE_MAX);
        }
        if ($rendered['description_chars'] > self::DESCRIPTION_MAX) {
            $w[] = sprintf('Meta description is %d characters; keep it under %d.', $rendered['description_chars'], self::DESCRIPTION_MAX);
        } elseif ($rendered['description_chars'] > 0 && $rendered['description_chars'] < self::DESCRIPTION_MIN) {
            $w[] = sprintf('Meta description is only %d characters; aim for %d–%d.', $rendered['description_chars'], self::DESCRIPTION_MIN, self::DESCRIPTION_MAX);
        }
        return $w;
    }
}
