<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Language_Resolver
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function resolve(int $post_id): array
    {
        $override = $this->normalize_language((string) get_post_meta($post_id, '_content_assistant_prompt_language', true));
        if ($override !== 'auto') {
            return ['language' => $override, 'source' => 'post_override'];
        }

        $detected = $this->detect_post_language($post_id);
        if ($detected !== '') {
            return ['language' => $detected, 'source' => 'multilingual_plugin'];
        }

        $plugin_default = $this->normalize_language((string) $this->config->get('general.language', 'auto'));
        if ($plugin_default !== 'auto') {
            return ['language' => $plugin_default, 'source' => 'plugin_default'];
        }

        $site = $this->locale_to_language(function_exists('get_locale') ? (string) get_locale() : '');
        if ($site !== '') {
            return ['language' => $site, 'source' => 'site_locale'];
        }

        return ['language' => 'de', 'source' => 'fallback'];
    }

    public function detect_post_language(int $post_id): string
    {
        if (function_exists('pll_get_post_language')) {
            $language = pll_get_post_language($post_id, 'slug');
            $language = $this->normalize_language(is_string($language) ? $language : '');
            if ($language !== 'auto') {
                return $language;
            }
        }

        if (has_filter('wpml_post_language_details')) {
            $details = apply_filters('wpml_post_language_details', null, $post_id);
            if (is_array($details)) {
                foreach (['language_code', 'code', 'locale'] as $key) {
                    if (!empty($details[$key])) {
                        $language = $this->normalize_language((string) $details[$key]);
                        if ($language !== 'auto') {
                            return $language;
                        }
                    }
                }
            }
        }

        return '';
    }

    public function normalize_language(string $language): string
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

    private function locale_to_language(string $locale): string
    {
        $language = $this->normalize_language($locale);
        return $language === 'auto' ? '' : $language;
    }
}
