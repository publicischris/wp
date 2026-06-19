<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Publications
{
    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'content_assistant_publications';
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(50) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'draft',
            title VARCHAR(255) NULL,
            content LONGTEXT NULL,
            url TEXT NULL,
            published_at DATETIME NULL,
            metrics LONGTEXT NULL,
            notes LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY channel (channel),
            KEY status (status),
            KEY published_at (published_at)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    public function create_snapshot(int $post_id, array $meta, string $channel = 'instagram'): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $metrics = [
            'likes' => $this->metric($meta, '_kimapa_instagram_likes'),
            'comments' => $this->metric($meta, '_kimapa_instagram_comments'),
            'shares' => $this->metric($meta, '_kimapa_instagram_shares'),
            'saves' => $this->metric($meta, '_kimapa_instagram_saves'),
            'reach' => $this->metric($meta, '_kimapa_instagram_reach'),
            'impressions' => $this->metric($meta, '_kimapa_instagram_impressions'),
        ];
        $content = $channel === 'newsletter' ? ($meta['_kimapa_newsletter_teaser'] ?? '') : ($meta['_kimapa_instagram_caption'] ?? '');
        $wpdb->insert(self::table_name(), [
            'post_id' => $post_id,
            'channel' => sanitize_key($channel),
            'status' => 'snapshot',
            'title' => wp_trim_words(get_the_title($post_id), 20, ''),
            'content' => sanitize_textarea_field($content),
            'url' => esc_url_raw((string) ($meta['_kimapa_instagram_url'] ?? '')),
            'published_at' => null,
            'metrics' => wp_json_encode($metrics),
            'notes' => sanitize_textarea_field((string) ($meta['_kimapa_editorial_improvement_notes'] ?? '')),
            'created_by' => get_current_user_id() ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
        return (int) $wpdb->insert_id;
    }

    public function latest(int $post_id, int $limit = 5): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE post_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', $post_id, $limit), ARRAY_A) ?: [];
    }

    private function metric(array $meta, string $key): int
    {
        return isset($meta[$key]) && $meta[$key] !== '' ? absint($meta[$key]) : 0;
    }
}
