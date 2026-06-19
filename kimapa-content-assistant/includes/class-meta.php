<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Meta
{
    public const KEYS = [
        '_kimapa_content_score',
        '_kimapa_content_checks',
        '_kimapa_generated_prompt',
        '_kimapa_instagram_caption',
        '_kimapa_instagram_hashtags',
        '_kimapa_instagram_cta',
        '_kimapa_instagram_story_idea',
        '_kimapa_instagram_carousel_idea',
        '_kimapa_newsletter_teaser',
        '_kimapa_instagram_url',
        '_kimapa_instagram_likes',
        '_kimapa_instagram_comments',
        '_kimapa_instagram_shares',
        '_kimapa_instagram_saves',
        '_kimapa_instagram_reach',
        '_kimapa_instagram_impressions',
        '_kimapa_last_ai_analysis_at',
    ];

    public function get_all(int $post_id): array
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = get_post_meta($post_id, $key, true);
        }

        return $values;
    }

    public function save_manual_fields(int $post_id, array $data): void
    {
        $textareas = [
            '_kimapa_instagram_caption',
            '_kimapa_instagram_hashtags',
            '_kimapa_instagram_cta',
            '_kimapa_instagram_story_idea',
            '_kimapa_instagram_carousel_idea',
            '_kimapa_newsletter_teaser',
        ];
        foreach ($textareas as $key) {
            update_post_meta($post_id, $key, isset($data[$key]) ? sanitize_textarea_field(wp_unslash($data[$key])) : '');
        }

        update_post_meta($post_id, '_kimapa_instagram_url', isset($data['_kimapa_instagram_url']) ? esc_url_raw(wp_unslash($data['_kimapa_instagram_url'])) : '');

        foreach ($this->integer_keys() as $key) {
            $value = isset($data[$key]) ? absint($data[$key]) : 0;
            update_post_meta($post_id, $key, $value);
        }
    }

    public function save_analysis(int $post_id, array $analysis, string $prompt): void
    {
        update_post_meta($post_id, '_kimapa_content_score', absint($analysis['score'] ?? 0));
        update_post_meta($post_id, '_kimapa_content_checks', wp_json_encode($analysis['checks'] ?? []));
        update_post_meta($post_id, '_kimapa_generated_prompt', wp_kses_post($prompt));
        update_post_meta($post_id, '_kimapa_last_ai_analysis_at', current_time('mysql'));
    }

    private function integer_keys(): array
    {
        return [
            '_kimapa_instagram_likes',
            '_kimapa_instagram_comments',
            '_kimapa_instagram_shares',
            '_kimapa_instagram_saves',
            '_kimapa_instagram_reach',
            '_kimapa_instagram_impressions',
        ];
    }
}
