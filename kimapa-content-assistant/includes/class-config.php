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

    public function apply_preset(string $profile): void
    {
        $config = $this->all();
        $presets = (array) ($config['content_profiles'] ?? []);
        if (empty($presets[$profile]) || !is_array($presets[$profile])) {
            return;
        }
        $preset = $presets[$profile];
        $config['general']['content_profile'] = $profile;
        foreach (['brand_guidance', 'editorial_rules', 'content_consistency_checks', 'required_output', 'format_settings'] as $key) {
            if (array_key_exists($key, $preset)) {
                $config[$key] = $preset[$key];
            }
        }
        if (isset($preset['brand_guidance'])) {
            $config['tone'] = $preset['brand_guidance']['tone'] ?? [];
            $config['avoid_phrases'] = $preset['brand_guidance']['avoid_phrases'] ?? [];
        }
        $this->data = $config;
        update_option(self::OPTION_NAME, $config, false);
    }

    public function sanitize(array $data): array
    {
        return [
            'general' => [
                'plugin_name' => sanitize_text_field($data['general']['plugin_name'] ?? ''),
                'brand_name' => sanitize_text_field($data['general']['brand_name'] ?? ''),
                'portal_description' => sanitize_textarea_field($data['general']['portal_description'] ?? ''),
                'language' => $this->normalize_language((string) ($data['general']['language'] ?? 'auto')),
                'post_types' => $this->lines_to_array($data['general']['post_types'] ?? ['post'], 'sanitize_key'),
                'content_profile' => sanitize_key($data['general']['content_profile'] ?? 'generic_editorial'),
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
            'quality_checks' => [
                'internal_editorial_notes' => [
                    'enabled' => !isset($data['quality_checks']['internal_editorial_notes']['enabled']) || !empty($data['quality_checks']['internal_editorial_notes']['enabled']),
                    'max_snippets' => max(1, absint($data['quality_checks']['internal_editorial_notes']['max_snippets'] ?? 8)),
                    'snippet_length' => max(80, absint($data['quality_checks']['internal_editorial_notes']['snippet_length'] ?? 160)),
                    'terms' => $this->lines_to_array($data['quality_checks']['internal_editorial_notes']['terms'] ?? $this->get('quality_checks.internal_editorial_notes.terms', $this->defaults_from_file()['quality_checks']['internal_editorial_notes']['terms'] ?? [])),
                ],
                'typo_hints' => [
                    'enabled' => !isset($data['quality_checks']['typo_hints']['enabled']) || !empty($data['quality_checks']['typo_hints']['enabled']),
                    'terms' => $this->sanitize_assoc_terms($data['quality_checks']['typo_hints']['terms'] ?? $this->get('quality_checks.typo_hints.terms', $this->defaults_from_file()['quality_checks']['typo_hints']['terms'] ?? [])),
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


    private function normalize_language(string $language): string
    {
        $language = strtolower(trim(str_replace('-', '_', $language)));
        if ($language === '' || $language === 'auto') {
            return 'auto';
        }
        if (in_array($language, ['de', 'de_de', 'deutsch', 'german'], true)) {
            return 'de';
        }
        if (in_array($language, ['en', 'en_us', 'en_gb', 'english'], true)) {
            return 'en';
        }
        $short = substr($language, 0, 2);
        return in_array($short, ['de', 'en'], true) ? $short : 'auto';
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

    private function sanitize_assoc_terms($value): array
    {
        $items = [];
        if (is_string($value)) {
            foreach (preg_split('/\R/u', $value) ?: [] as $line) {
                if (strpos($line, '=>') !== false) {
                    [$from, $to] = array_map('trim', explode('=>', $line, 2));
                    if ($from !== '' && $to !== '') {
                        $items[sanitize_text_field($from)] = sanitize_text_field($to);
                    }
                }
            }
            return $items;
        }
        foreach ((array) $value as $from => $to) {
            $from = sanitize_text_field((string) $from);
            $to = sanitize_text_field((string) $to);
            if ($from !== '' && $to !== '') {
                $items[$from] = $to;
            }
        }
        return $items;
    }

    private function fallback_defaults(): array
    {
        return [
            'general' => ['plugin_name' => 'Content Assistant', 'brand_name' => 'Your Brand', 'portal_description' => 'Editorial website or content platform.', 'language' => 'auto', 'post_types' => ['post'], 'content_profile' => 'generic_editorial'],
            'channels' => ['instagram' => true, 'newsletter' => true, 'editorial_review' => true, 'debug' => true],
            'brand_guidance' => ['tone' => ['clear and understandable'], 'avoid_phrases' => []],
            'tone' => ['clear and understandable'],
            'avoid_phrases' => [],
            'editorial_rules' => ['Do not invent facts.'],
            'required_output' => ['suggested_excerpt', 'editorial_improvement_notes'],
            'format_settings' => ['preferred' => 'JSON', 'instagram_caption_variants' => 3, 'hashtag_count' => '8-15', 'include_hook' => true, 'include_cta' => true, 'newsletter_max_characters' => 450, 'newsletter_style' => ''],
            'structured_fields' => ['enabled' => false, 'fallback_to_content_parsing' => true, 'meta_keys' => ['latitude' => '', 'longitude' => '', 'google_maps_link' => '', 'street' => '', 'zip' => '', 'city' => '', 'external_link' => '', 'image_credit' => '']],
            'quality_checks' => ['internal_editorial_notes' => ['enabled' => true, 'max_snippets' => 8, 'snippet_length' => 160, 'terms' => ['Mein Vorschlag', 'Vorschlag:', 'Anmerkung:', 'TODO', 'ToDo', 'To-do', 'prüfen', 'bitte prüfen', 'noch ergänzen', 'noch einfügen', 'hier ergänzen', 'hier einfügen', 'Platzhalter', 'Dummy', 'Lorem ipsum', 'wenn ja, dann', 'würde ich es so schreiben', 'habt ihr', 'könnt ihr', 'bitte noch', 'kommt noch', 'folgt noch']], 'typo_hints' => ['enabled' => true, 'terms' => ['Mautraße' => 'Mautstraße', 'abegrissen' => 'abgerissen', 'denn See' => 'den See', 'Lenggies' => 'Lenggries']]],
            'wordpress_checks' => ['min_word_count' => 450, 'featured_image_min_width' => 1200, 'featured_image_min_height' => 800, 'stale_after_days' => 365, 'relative_time_terms' => []],
            'content_consistency_checks' => [],
            'scoring' => ['labels' => []],
        ];
    }
}
