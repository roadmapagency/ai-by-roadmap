<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core\Abilities;

use Roadmap\AiByRoadmap\Core\Seo;
use WP_Error;

/**
 * Read one post's SEO data as stored in Yoast SEO, what the front end will
 * render from it, Yoast's analysis scores, and the obvious problems.
 */
final class GetPostSeo
{
    public const ID = 'ai-by-roadmap/get-post-seo';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'                => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'            => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'               => __('Get a post\'s SEO data (Yoast)', 'ai-by-roadmap'),
            'description'         => __('Read a post\'s SEO data from Yoast SEO: the stored fields (seo_title, meta_description, focus_keyphrase, canonical, noindex, Open Graph / Twitter overrides…), what the front end will actually render (title and description with Yoast templates applied, with character counts), Yoast\'s SEO and readability scores, and a list of issues (missing_description, description_too_long, title_too_long, missing_focus_keyphrase, noindex). Identify the post by post_id or permalink. Use audit-seo for the whole site and update-post-seo to fix a post.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => [
                    'post_id'   => ['type' => 'integer'],
                    'permalink' => ['type' => 'string', 'description' => 'Alternative to post_id: a URL or path on this site.'],
                ],
            ],
            'output_schema'       => self::report_schema(),
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function report_schema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => true,
            'required'             => ['post_id', 'permalink', 'fields', 'rendered', 'scores', 'issues'],
            'properties'           => [
                'post_id'    => ['type' => 'integer'],
                'post_title' => ['type' => 'string'],
                'post_type'  => ['type' => 'string'],
                'status'     => ['type' => 'string'],
                'permalink'  => ['type' => 'string'],
                'fields'     => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Stored values by field name: ' . implode(', ', array_keys(Seo::FIELDS)) . '. noindex is true/false/null (null = post-type default).'],
                'rendered'   => ['type' => 'object', 'additionalProperties' => true, 'description' => 'title, title_chars, description, description_chars, canonical, robots, indexable — as the front end outputs them.'],
                'scores'     => ['type' => 'object', 'additionalProperties' => true, 'description' => 'seo_score and readability_score (0–100, 0 when not analysed yet).'],
                'issues'     => ['type' => 'array', 'items' => ['type' => 'string']],
                'edit_link'  => ['type' => 'string'],
            ],
        ];
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
        return Seo::report($post);
    }
}
