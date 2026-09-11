<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Jobs\ComposePageJob;

/**
 * The "easy button" Jennifer's batch workflow calls 56 times. Enqueues the
 * full pipeline as an Action Scheduler job and returns immediately with a
 * job ID; callers poll get-job-status for the result.
 */
final class ComposePage
{
    public const ID = 'ai-by-roadmap/compose-page';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => \Roadmap\AiByRoadmap\Plugin::ability_meta(false, true, false),
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Compose a full page from source content', 'ai-by-roadmap'),
            'description'         => __('Run the full analyze → choose → score → fill → transform pipeline asynchronously. Returns a job_id immediately; poll get-job-status to retrieve the serialized blocks once the pipeline completes.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['content'],
                'properties'           => [
                    'content'         => ['type' => 'string'],
                    'target_audience' => ['type' => 'string'],
                    'post_id'         => [
                        'type'        => 'integer',
                        'description' => 'Optional post ID. If provided and replace_content is true, that post will be overwritten when the job completes. If omitted, a new draft page is created and its ID is reported back via get-job-status.',
                    ],
                    'replace_content' => [
                        'type'        => 'boolean',
                        'description' => 'When post_id is provided, set true to overwrite its content. Ignored when post_id is omitted (a draft is always created in that case).',
                    ],
                    'title'           => [
                        'type'        => 'string',
                        'description' => 'Optional title for the draft page when post_id is omitted. Defaults to "AI generated page" plus a timestamp.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['job_id', 'status'],
                'properties'           => [
                    'job_id' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $job_id = ComposePageJob::enqueue([
            'content'         => (string) $input['content'],
            'target_audience' => (string) ($input['target_audience'] ?? ''),
            'post_id'         => isset($input['post_id']) ? (int) $input['post_id'] : null,
            'replace_content' => ! empty($input['replace_content']),
            'title'           => (string) ($input['title'] ?? ''),
        ]);

        return ['job_id' => $job_id, 'status' => 'queued'];
    }
}
