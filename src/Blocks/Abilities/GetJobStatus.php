<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Blocks\Abilities;

use Roadmap\AiByRoadmap\Jobs\JobStore;

final class GetJobStatus
{
    public const ID = 'ai-by-roadmap/get-job-status';

    public static function register(): void
    {
        wp_register_ability(self::ID, [
            'meta'           => \Roadmap\AiByRoadmap\Plugin::ability_meta(true),
            'category'           => \Roadmap\AiByRoadmap\Categories::SLUG,
            'label'              => __('Get compose-page job status', 'ai-by-roadmap'),
            'description'         => __('Poll for the status of an async page-composition job. Returns the current status (queued, running, done, failed) plus the result payload once finished.', 'ai-by-roadmap'),
            'input_schema'        => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['job_id'],
                'properties'           => [
                    'job_id' => ['type' => 'string'],
                ],
            ],
            'output_schema'       => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['status'],
                'properties'           => [
                    'status' => ['type' => 'string'],
                    'result' => ['type' => 'object', 'additionalProperties' => true],
                    'error'  => ['type' => 'string'],
                ],
            ],
            'permission_callback' => static fn(): bool => current_user_can('edit_posts'),
            'execute_callback'    => [self::class, 'execute'],
        ]);
    }

    public static function execute(array $input): array
    {
        $job = JobStore::get((string) $input['job_id']);
        if (! $job) {
            return ['status' => 'unknown'];
        }

        $out = ['status' => $job['status']];
        if ($job['result'] !== null) {
            $out['result'] = $job['result'];
        }
        if (! empty($job['error'])) {
            $out['error'] = (string) $job['error'];
        }
        return $out;
    }
}
