<?php

declare(strict_types=1);

namespace Roadmap\AiByRoadmap\Core;

/**
 * Stores and queries image embeddings in a MariaDB native VECTOR column.
 * No-ops when the host database does not support VECTOR — callers must
 * guard with VectorSupport::is_available() before invoking.
 */
final class VectorStore
{
    private const DIMENSIONS = 1536;

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ai_image_embeddings';
    }

    public static function create_table(): void
    {
        if (! VectorSupport::is_available()) {
            return;
        }

        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `attachment_id` BIGINT(20) UNSIGNED NOT NULL,
            `embedding`     VECTOR(" . self::DIMENSIONS . ") NOT NULL,
            PRIMARY KEY (`attachment_id`)
        ) ENGINE=InnoDB {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND INDEX_NAME = 'embedding_idx'",
            $table
        ));

        if (! $index_exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD VECTOR INDEX `embedding_idx` (`embedding`)");
        }
    }

    /**
     * @param float[] $embedding
     */
    public static function upsert(int $attachment_id, array $embedding): void
    {
        if (! VectorSupport::is_available()) {
            return;
        }

        if (count($embedding) !== self::DIMENSIONS) {
            throw new \InvalidArgumentException(sprintf(
                'Embedding has %d dimensions; expected %d.',
                count($embedding),
                self::DIMENSIONS
            ));
        }

        global $wpdb;
        $table  = self::table_name();
        $vector = wp_json_encode(array_values($embedding));

        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$table}` (`attachment_id`, `embedding`)
             VALUES (%d, VEC_FromText(%s))
             ON DUPLICATE KEY UPDATE `embedding` = VEC_FromText(%s)",
            $attachment_id,
            $vector,
            $vector
        ));
    }

    /**
     * @param  float[] $query_embedding
     * @return int[]   Attachment IDs ordered by cosine similarity (closest first).
     */
    public static function search(array $query_embedding, int $limit = 5): array
    {
        if (! VectorSupport::is_available()) {
            return [];
        }

        global $wpdb;
        $table  = self::table_name();
        $vector = wp_json_encode(array_values($query_embedding));

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT `attachment_id`
             FROM `{$table}`
             ORDER BY VEC_DISTANCE_COSINE(`embedding`, VEC_FromText(%s)) ASC
             LIMIT %d",
            $vector,
            $limit
        ));

        return array_map('intval', $results ?: []);
    }

    public static function delete(int $attachment_id): void
    {
        if (! VectorSupport::is_available()) {
            return;
        }

        global $wpdb;
        $wpdb->delete(self::table_name(), ['attachment_id' => $attachment_id], ['%d']);
    }

    public static function has_rows(): bool
    {
        if (! VectorSupport::is_available()) {
            return false;
        }

        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::table_name() . '`') > 0;
    }
}
