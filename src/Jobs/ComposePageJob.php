<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Jobs;

use Roadmap\AiByRoadmap\Blocks\Orchestrator;

/**
 * Action Scheduler hook that runs the full compose-page pipeline in the
 * background. The ComposePage ability enqueues a job and returns immediately;
 * callers poll get-job-status for the result.
 */
final class ComposePageJob
{
    public const HOOK  = 'ai_by_roadmap_compose_page';
    public const GROUP = 'ai-by-roadmap';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run'], 10, 1);
    }

    public static function enqueue(array $payload): string
    {
        $job_id = JobStore::create($payload);

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, ['job_id' => $job_id], self::GROUP);
        } else {
            // Action Scheduler not loaded — fall back to wp-cron single event.
            wp_schedule_single_event(time(), self::HOOK, ['job_id' => $job_id]);
        }

        return $job_id;
    }

    public function run(string $job_id): void
    {
        $job = JobStore::get($job_id);
        if (! $job) {
            return;
        }

        JobStore::update_status($job_id, JobStore::STATUS_RUNNING);

        try {
            $payload = $job['payload'];
            $post_id = isset($payload['post_id']) ? (int) $payload['post_id'] : null;

            $blocks = (new Orchestrator())->generate_content(
                (string) ($payload['content']         ?? ''),
                (string) ($payload['target_audience'] ?? ''),
                $post_id
            );

            $result = ['blocks' => $blocks];
            $serialized = implode("\n\n", $blocks);

            if ($post_id && ! empty($payload['replace_content'])) {
                wp_update_post([
                    'ID'           => $post_id,
                    // wp_update_post() runs wp_unslash(); slash so ACF's
                    // <-escaped block attributes survive intact.
                    'post_content' => wp_slash($serialized),
                ]);
                $result['post_id']   = $post_id;
                $result['edit_link'] = (string) get_edit_post_link($post_id, 'raw');
            } elseif (! $post_id) {
                // No post supplied — create a fresh draft page so the user has
                // something visible to review and publish.
                $title = (string) ($payload['title'] ?? '');
                if ($title === '') {
                    $title = sprintf(
                        __('AI generated page — %s', 'ai-by-roadmap'),
                        date_i18n('M j, Y \a\t g:ia')
                    );
                }

                $new_id = wp_insert_post([
                    'post_type'    => 'page',
                    'post_status'  => 'draft',
                    'post_title'   => $title,
                    // wp_insert_post() runs wp_unslash(); slash so ACF's
                    // <-escaped block attributes survive intact.
                    'post_content' => wp_slash($serialized),
                ], true);

                if (is_wp_error($new_id)) {
                    throw new \RuntimeException('Could not create draft page: ' . $new_id->get_error_message());
                }

                $result['post_id']   = (int) $new_id;
                $result['edit_link'] = (string) get_edit_post_link((int) $new_id, 'raw');
            }

            JobStore::complete($job_id, $result);
        } catch (\Throwable $e) {
            JobStore::fail($job_id, $e->getMessage());
            error_log('AI by Roadmap: compose-page job ' . $job_id . ' failed: ' . $e->getMessage());
        }
    }
}
