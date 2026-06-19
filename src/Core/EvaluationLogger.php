<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core;

/**
 * Persists one row per Orchestrator run plus user feedback. A daily WP-Cron
 * job prunes the table down to the most recent N rows (default 1,000).
 */
final class EvaluationLogger
{
    private const PRUNE_HOOK    = 'ai_by_roadmap_prune_log';
    private const DEFAULT_KEEP  = 1000;

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ai_generation_log';
    }

    public static function create_table(): void
    {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at`         DATETIME            NOT NULL,
            `post_id`            BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            `input_content`      LONGTEXT            NOT NULL,
            `content_signals`    LONGTEXT            NOT NULL,
            `chosen_blocks`      LONGTEXT            NOT NULL,
            `scorer_result`      LONGTEXT            NOT NULL,
            `retry_happened`     TINYINT(1)          NOT NULL DEFAULT 0,
            `retry_prompt`       TEXT                NULL DEFAULT NULL,
            `prompt_suggestion`  TEXT                NULL DEFAULT NULL,
            `final_blocks`       LONGTEXT            NOT NULL,
            `feedback_rating`    TINYINT(1)          NOT NULL DEFAULT 0,
            `feedback_notes`     TEXT                NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `created_at_idx` (`created_at`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function register_prune_cron(): void
    {
        add_action(self::PRUNE_HOOK, [self::class, 'prune']);

        if (! wp_next_scheduled(self::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK);
        }
    }

    public static function prune(): void
    {
        global $wpdb;
        $keep  = (int) apply_filters('ai_by_roadmap_log_retention', self::DEFAULT_KEEP);
        $keep  = max(50, $keep);
        $table = self::table_name();

        $cutoff_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1 OFFSET %d",
            $keep
        ));

        if ($cutoff_id > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE id <= %d",
                $cutoff_id
            ));
        }
    }

    public static function log(array $data): int
    {
        global $wpdb;

        $wpdb->insert(
            self::table_name(),
            [
                'created_at'        => current_time('mysql', true),
                'post_id'           => $data['post_id'] ?? null,
                'input_content'     => $data['input_content'] ?? '',
                'content_signals'   => wp_json_encode($data['content_signals'] ?? []),
                'chosen_blocks'     => wp_json_encode($data['chosen_blocks'] ?? []),
                'scorer_result'     => wp_json_encode($data['scorer_result'] ?? []),
                'retry_happened'    => ! empty($data['retry_happened']) ? 1 : 0,
                'retry_prompt'      => $data['retry_prompt'] ?? null,
                'prompt_suggestion' => $data['prompt_suggestion'] ?? null,
                'final_blocks'      => wp_json_encode($data['final_blocks'] ?? []),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public static function update_feedback(int $id, int $rating, string $notes = ''): void
    {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            ['feedback_rating' => $rating, 'feedback_notes' => $notes !== '' ? $notes : null],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
    }

    public static function get_negative_examples(int $limit = 3): array
    {
        global $wpdb;
        $table    = self::table_name();
        $suppress = $wpdb->suppress_errors(true);
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT input_content, final_blocks, feedback_notes
                 FROM `{$table}`
                 WHERE feedback_rating = -1
                 ORDER BY created_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        $wpdb->suppress_errors($suppress);
        return $rows ?? [];
    }

    public static function get_recent(int $limit = 50): array
    {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d", $limit),
            ARRAY_A
        ) ?? [];
    }
}
