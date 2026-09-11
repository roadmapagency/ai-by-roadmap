<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\Seo;
use WP_Error;

/**
 * Write a post's SEO data through Yoast SEO's storage API. Merge semantics:
 * only the fields supplied change; an empty string clears a field.
 */
final class UpdatePostSeo
{
    public const ID = 'ai-by-roadmap/update-post-seo';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, false, true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Update a post\'s SEO data (Yoast)', 'ai-by-roadmap'),
            'description'         => __('Set a post\'s Yoast SEO fields: seo_title, meta_description, focus_keyphrase, canonical, noindex (true/false/null for default), nofollow, breadcrumb_title, is_cornerstone, open_graph_title/description/image, twitter_title/description/image. Only the fields you pass change; "" clears one. seo_title may use Yoast variables (%%title%%, %%sep%%, %%sitename%%); leave it empty to keep the post-type template. Returns the post\'s full SEO report afterwards plus soft warnings when the rendered title exceeds ~60 characters or the description falls outside 70–156. Call get-post-seo or audit-seo first to see what needs work. (Same field names as Yoast\'s own update-post-seo-data ability, which appears here when Yoast registers it.)', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['fields'],
                'properties'           => [
                    'post_id'   => ['type' => 'integer'],
                    'permalink' => ['type' => 'string', 'description' => 'Alternative to post_id: a URL or path on this site.'],
                    'fields'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'minProperties'        => 1,
                        'properties'           => [
                            'seo_title'              => ['type' => 'string'],
                            'meta_description'       => ['type' => 'string'],
                            'focus_keyphrase'        => ['type' => 'string'],
                            'canonical'              => ['type' => 'string', 'description' => 'Absolute URL or site-relative path; "" to clear.'],
                            'noindex'                => ['type' => ['boolean', 'null'], 'description' => 'true = noindex, false = index, null = post-type default.'],
                            'nofollow'               => ['type' => 'boolean'],
                            'breadcrumb_title'       => ['type' => 'string'],
                            'is_cornerstone'         => ['type' => 'boolean'],
                            'open_graph_title'       => ['type' => 'string'],
                            'open_graph_description' => ['type' => 'string'],
                            'open_graph_image'       => ['type' => 'integer', 'description' => 'Attachment ID; 0 clears.'],
                            'twitter_title'          => ['type' => 'string'],
                            'twitter_description'    => ['type' => 'string'],
                            'twitter_image'          => ['type' => 'integer', 'description' => 'Attachment ID; 0 clears.'],
                        ],
                    ],
                ],
            ],
            'output_schema'       => array_merge_recursive(GetPostSeo::report_schema(), [
                'properties' => [
                    'success'        => ['type' => 'boolean'],
                    'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'warnings'       => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ]),
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public static function execute(array $input)
    {
        if (! Seo::available()) {
            return Seo::unavailable_error();
        }
        $post = Seo::resolve_post($input);
        if (is_wp_error($post)) {
            return $post;
        }
        $fields = (array) ($input['fields'] ?? []);
        if ($fields === []) {
            return new WP_Error('empty_patch', __('fields is empty — supply at least one SEO field.', 'ai-by-roadmap'), ['status' => 400]);
        }

        $changed = Seo::write((int) $post->ID, $fields);
        if (is_wp_error($changed)) {
            return $changed;
        }

        clean_post_cache((int) $post->ID);
        $report = Seo::report(get_post((int) $post->ID) ?: $post);

        return ['success' => true, 'changed_fields' => $changed, 'warnings' => Seo::warnings($report['rendered'])] + $report;
    }
}
