<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Jobs;

/**
 * Simple job-state table. Action Scheduler runs the work; this table records
 * the outcome so REST/MCP callers can poll for status without inspecting
 * Action Scheduler's internals.
 */
final class JobStore
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ai_by_roadmap_jobs';
    }

    public static function create_table(): void
    {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`          VARCHAR(36)         NOT NULL,
            `status`      VARCHAR(16)         NOT NULL DEFAULT 'queued',
            `payload`     LONGTEXT            NOT NULL,
            `result`      LONGTEXT            NULL DEFAULT NULL,
            `error`       TEXT                NULL DEFAULT NULL,
            `created_at`  DATETIME            NOT NULL,
            `updated_at`  DATETIME            NOT NULL,
            PRIMARY KEY (`id`),
            KEY `status_idx` (`status`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function create(array $payload): string
    {
        global $wpdb;
        $id  = wp_generate_uuid4();
        $now = current_time('mysql', true);

        $result = $wpdb->insert(
            self::table_name(),
            [
                'id'         => $id,
                'status'     => self::STATUS_QUEUED,
                'payload'    => wp_json_encode($payload),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            throw new \RuntimeException(sprintf(
                'AI by Roadmap: failed to create job row (%s).',
                $wpdb->last_error ?: 'unknown'
            ));
        }

        return $id;
    }

    public static function update_status(string $id, string $status): void
    {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            ['status' => $status, 'updated_at' => current_time('mysql', true)],
            ['id' => $id],
            ['%s', '%s'],
            ['%s']
        );
    }

    public static function complete(string $id, array $result): void
    {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [
                'status'     => self::STATUS_DONE,
                'result'     => wp_json_encode($result),
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%s']
        );
    }

    public static function fail(string $id, string $error): void
    {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [
                'status'     => self::STATUS_FAILED,
                'error'      => $error,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * @return array{id:string,status:string,payload:array,result:?array,error:?string,created_at:string,updated_at:string}|null
     */
    public static function get(string $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `" . self::table_name() . "` WHERE id = %s", $id),
            ARRAY_A
        );
        if (! $row) {
            return null;
        }

        $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        $row['result']  = $row['result'] !== null ? (json_decode((string) $row['result'], true) ?: null) : null;
        return $row;
    }
}
