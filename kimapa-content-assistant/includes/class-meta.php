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
        '_kimapa_extracted_facts',
        '_kimapa_advertising_disclosure_detected',
        '_kimapa_detected_relative_time_terms',
        '_kimapa_detected_outdated_terms',
        '_kimapa_placeholder_excerpt_detected',
        '_kimapa_ai_result_raw',
        '_kimapa_editorial_improvement_notes',
        '_kimapa_instagram_caption_variant_1',
        '_kimapa_instagram_caption_variant_2',
        '_kimapa_instagram_caption_variant_3',
        '_kimapa_hook',
        '_kimapa_dates_without_year',
        '_content_assistant_prompt_language',
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
            '_kimapa_ai_result_raw',
            '_kimapa_editorial_improvement_notes',
            '_kimapa_instagram_caption_variant_1',
            '_kimapa_instagram_caption_variant_2',
            '_kimapa_instagram_caption_variant_3',
            '_kimapa_hook',
        ];
        foreach ($textareas as $key) {
            update_post_meta($post_id, $key, isset($data[$key]) ? sanitize_textarea_field(wp_unslash($data[$key])) : '');
        }

        $language = isset($data['_content_assistant_prompt_language']) ? sanitize_key(wp_unslash($data['_content_assistant_prompt_language'])) : 'auto';
        update_post_meta($post_id, '_content_assistant_prompt_language', in_array($language, ['auto', 'de', 'en'], true) ? $language : 'auto');

        $instagram_url = isset($data['_kimapa_instagram_url']) ? trim((string) wp_unslash($data['_kimapa_instagram_url'])) : '';
        update_post_meta($post_id, '_kimapa_instagram_url', $instagram_url !== '' ? esc_url_raw($instagram_url) : '');

        foreach ($this->integer_keys() as $key) {
            $raw = isset($data[$key]) ? trim((string) wp_unslash($data[$key])) : '';
            update_post_meta($post_id, $key, $raw === '' ? '' : min(absint($raw), 999999999));
        }
    }

    public function save_analysis(int $post_id, array $analysis, string $prompt): void
    {
        update_post_meta($post_id, '_kimapa_content_score', absint($analysis['score'] ?? 0));
        update_post_meta($post_id, '_kimapa_content_checks', wp_json_encode($analysis['checks'] ?? []));
        update_post_meta($post_id, '_kimapa_generated_prompt', wp_kses_post($prompt));
        update_post_meta($post_id, '_kimapa_extracted_facts', wp_json_encode($analysis['extracted_facts'] ?? []));
        update_post_meta($post_id, '_kimapa_advertising_disclosure_detected', !empty($analysis['advertising_disclosure_detected']) ? '1' : '0');
        update_post_meta($post_id, '_kimapa_detected_relative_time_terms', wp_json_encode($analysis['detected_relative_time_terms'] ?? []));
        update_post_meta($post_id, '_kimapa_detected_outdated_terms', wp_json_encode($analysis['detected_outdated_terms'] ?? []));
        update_post_meta($post_id, '_kimapa_placeholder_excerpt_detected', !empty($analysis['placeholder_excerpt_detected']) ? '1' : '0');
        update_post_meta($post_id, '_kimapa_dates_without_year', wp_json_encode($analysis['dates_without_year'] ?? []));
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
