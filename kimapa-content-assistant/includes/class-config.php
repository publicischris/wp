<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Config
{
    public const OPTION_NAME = 'kimapa_content_assistant_config';

    /** @var string */
    private $path;

    /** @var array|null */
    private $data = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function activate(string $path): void
    {
        if (false === get_option(self::OPTION_NAME, false)) {
            $config = new self($path);
            add_option(self::OPTION_NAME, $config->defaults_from_file(), '', false);
        }
    }

    public function all(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        $defaults = $this->defaults_from_file();
        $active = get_option(self::OPTION_NAME, []);
        if (!is_array($active)) {
            $active = [];
        }

        $this->data = array_replace_recursive($defaults, $active);
        return $this->data;
    }

    public function save(array $data): void
    {
        $this->data = array_replace_recursive($this->defaults_from_file(), $this->sanitize($data));
        update_option(self::OPTION_NAME, $this->data, false);
    }

    public function reset(): void
    {
        $this->data = $this->defaults_from_file();
        update_option(self::OPTION_NAME, $this->data, false);
    }

    public function get(string $key, $default = null)
    {
        $value = $this->all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function channels(): array
    {
        return (array) $this->get('channels', []);
    }

    public function is_channel_enabled(string $channel): bool
    {
        return !empty($this->channels()[$channel]);
    }

    public function required_output(): array
    {
        $output = (array) $this->get('required_output', []);
        if (!$this->is_channel_enabled('instagram')) {
            $output = array_filter($output, static function ($field) {
                return !in_array($field, ['instagram_caption_variant_1_emotional', 'instagram_caption_variant_2_practical', 'instagram_caption_variant_3_short', 'hook', 'cta', 'hashtags', 'story_idea', 'carousel_idea'], true);
            });
        }
        if (empty($this->get('format_settings.include_hook', true))) {
            $output = array_filter($output, static function ($field) { return $field !== 'hook'; });
        }
        if (empty($this->get('format_settings.include_cta', true))) {
            $output = array_filter($output, static function ($field) { return $field !== 'cta'; });
        }
        if (!$this->is_channel_enabled('newsletter')) {
            $output = array_filter($output, static function ($field) {
                return $field !== 'newsletter_teaser';
            });
        }
        if (!$this->is_channel_enabled('editorial_review')) {
            $output = array_filter($output, static function ($field) {
                return !in_array($field, ['suggested_excerpt', 'editorial_improvement_notes'], true);
            });
        }
        if (!$output) {
            $output = ['suggested_excerpt', 'editorial_improvement_notes'];
        }
        return array_values(array_unique($output));
    }

    public function sanitize(array $data): array
    {
        return [
            'general' => [
                'plugin_name' => sanitize_text_field($data['general']['plugin_name'] ?? ''),
                'brand_name' => sanitize_text_field($data['general']['brand_name'] ?? ''),
                'portal_description' => sanitize_textarea_field($data['general']['portal_description'] ?? ''),
                'language' => sanitize_key($data['general']['language'] ?? 'de'),
                'post_types' => $this->lines_to_array($data['general']['post_types'] ?? ['post'], 'sanitize_key'),
            ],
            'channels' => [
                'instagram' => !empty($data['channels']['instagram']),
                'newsletter' => !empty($data['channels']['newsletter']),
                'editorial_review' => !empty($data['channels']['editorial_review']),
                'debug' => !empty($data['channels']['debug']),
            ],
            'brand_guidance' => [
                'tone' => $this->lines_to_array($data['brand_guidance']['tone'] ?? []),
                'avoid_phrases' => $this->lines_to_array($data['brand_guidance']['avoid_phrases'] ?? []),
            ],
            'tone' => $this->lines_to_array($data['brand_guidance']['tone'] ?? []),
            'avoid_phrases' => $this->lines_to_array($data['brand_guidance']['avoid_phrases'] ?? []),
            'editorial_rules' => $this->lines_to_array($data['editorial_rules'] ?? []),
            'required_output' => $this->lines_to_array($data['required_output'] ?? [], 'sanitize_key'),
            'format_settings' => [
                'preferred' => sanitize_text_field($data['format_settings']['preferred'] ?? 'JSON'),
                'instagram_caption_variants' => max(1, absint($data['format_settings']['instagram_caption_variants'] ?? 3)),
                'hashtag_count' => sanitize_text_field($data['format_settings']['hashtag_count'] ?? '8-15'),
                'include_hook' => !empty($data['format_settings']['include_hook']),
                'include_cta' => !empty($data['format_settings']['include_cta']),
                'newsletter_max_characters' => max(1, absint($data['format_settings']['newsletter_max_characters'] ?? 450)),
                'newsletter_style' => sanitize_text_field($data['format_settings']['newsletter_style'] ?? ''),
            ],
            'structured_fields' => [
                'enabled' => !empty($data['structured_fields']['enabled']),
                'fallback_to_content_parsing' => !empty($data['structured_fields']['fallback_to_content_parsing']),
                'meta_keys' => [
                    'latitude' => sanitize_text_field($data['structured_fields']['meta_keys']['latitude'] ?? ''),
                    'longitude' => sanitize_text_field($data['structured_fields']['meta_keys']['longitude'] ?? ''),
                    'google_maps_link' => sanitize_text_field($data['structured_fields']['meta_keys']['google_maps_link'] ?? ''),
                    'street' => sanitize_text_field($data['structured_fields']['meta_keys']['street'] ?? ''),
                    'zip' => sanitize_text_field($data['structured_fields']['meta_keys']['zip'] ?? ''),
                    'city' => sanitize_text_field($data['structured_fields']['meta_keys']['city'] ?? ''),
                    'external_link' => sanitize_text_field($data['structured_fields']['meta_keys']['external_link'] ?? ''),
                    'image_credit' => sanitize_text_field($data['structured_fields']['meta_keys']['image_credit'] ?? ''),
                ],
            ],
        ];
    }

    public function defaults_from_file(): array
    {
        $fallback = $this->fallback_defaults();
        if (!is_readable($this->path)) {
            return $fallback;
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($decoded)) {
            return $fallback;
        }
        return array_replace_recursive($fallback, $decoded);
    }

    private function lines_to_array($value, callable $sanitize_callback = null): array
    {
        $items = is_array($value) ? $value : preg_split('/\R/u', (string) $value);
        $items = array_filter(array_map(static function ($item) use ($sanitize_callback) {
            $item = trim((string) $item);
            if ($item === '') {
                return '';
            }
            return $sanitize_callback ? call_user_func($sanitize_callback, $item) : sanitize_text_field($item);
        }, (array) $items));
        return array_values(array_unique($items));
    }

    private function fallback_defaults(): array
    {
        return [
            'general' => ['plugin_name' => 'KiMaPa Content Assistant', 'brand_name' => 'KiMaPa', 'portal_description' => '', 'language' => 'de', 'post_types' => ['post']],
            'channels' => ['instagram' => true, 'newsletter' => true, 'editorial_review' => true, 'debug' => true],
            'brand_guidance' => ['tone' => ['familiennah'], 'avoid_phrases' => []],
            'tone' => ['familiennah'],
            'avoid_phrases' => [],
            'editorial_rules' => ['Keine Fakten erfinden.'],
            'required_output' => ['suggested_excerpt', 'editorial_improvement_notes'],
            'format_settings' => ['preferred' => 'JSON', 'instagram_caption_variants' => 3, 'hashtag_count' => '8-15', 'include_hook' => true, 'include_cta' => true, 'newsletter_max_characters' => 450, 'newsletter_style' => ''],
            'structured_fields' => ['enabled' => false, 'fallback_to_content_parsing' => true, 'meta_keys' => ['latitude' => '', 'longitude' => '', 'google_maps_link' => '', 'street' => '', 'zip' => '', 'city' => '', 'external_link' => '', 'image_credit' => '']],
            'wordpress_checks' => ['min_word_count' => 450, 'featured_image_min_width' => 1200, 'featured_image_min_height' => 800, 'stale_after_days' => 365, 'relative_time_terms' => []],
            'content_consistency_checks' => [],
            'scoring' => ['labels' => []],
        ];
    }
}
