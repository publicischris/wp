<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Content_Extractor
{
    public function extract(int $post_id): array
    {
        $raw = (string) get_post_field('post_content', $post_id, 'raw');
        $content = $this->extract_readable_content($post_id, $raw);
        $word_count = $this->word_count($content);
        $excerpt_data = $this->extract_excerpt($post_id, $content);
        $warnings = [];
        $status = 'success';

        if (trim($raw) === '') {
            $status = 'empty';
            $warnings[] = __('Raw post content is empty.', 'kimapa-content-assistant');
        }

        if ($content === '') {
            $status = 'empty';
            $warnings[] = __('The post content could not be extracted. Please check the Avada/Fusion Builder structure.', 'kimapa-content-assistant');
        } elseif ($word_count < 80) {
            $status = 'short';
            $warnings[] = __('The extracted post content is very short. Please check whether builder content was fully detected.', 'kimapa-content-assistant');
        }

        return [
            'raw_content_present' => trim($raw) !== '',
            'content' => $content,
            'content_length' => mb_strlen($content),
            'content_word_count' => $word_count,
            'content_extraction_status' => $status,
            'content_extraction_warnings' => array_values(array_unique($warnings)),
            'excerpt' => $excerpt_data['excerpt'],
            'excerpt_source' => $excerpt_data['source'],
        ];
    }

    public function extract_readable_content(int $post_id, ?string $raw_content = null): string
    {
        $content = null === $raw_content ? (string) get_post_field('post_content', $post_id, 'raw') : $raw_content;
        if (trim($content) === '') {
            return '';
        }

        $content = $this->remove_technical_blocks($content);
        $content = $this->normalize_avada_shortcodes($content);
        $content = $this->preserve_shortcode_inner_text($content);
        $content = $this->remove_remaining_shortcode_artifacts($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $content = wp_strip_all_tags($content, true);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $content = preg_replace('/\[\/?[a-zA-Z0-9_:-]+[^\]]*\]/u', ' ', (string) $content);
        $content = preg_replace('/\s*\n\s*/u', "\n", (string) $content);
        $content = preg_replace('/[ \t]+/u', ' ', (string) $content);
        $content = preg_replace('/\n{3,}/u', "\n\n", (string) $content);
        $content = preg_replace('/\s+([,.!?;:])/u', '$1', (string) $content);

        return trim((string) $content);
    }

    public function word_count(string $content): int
    {
        if (trim($content) === '') {
            return 0;
        }

        preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $content, $matches);
        return count($matches[0]);
    }

    private function extract_excerpt(int $post_id, string $content): array
    {
        $manual = (string) get_post_field('post_excerpt', $post_id, 'raw');
        if (trim($manual) !== '') {
            return [
                'excerpt' => $this->clean_excerpt($manual, 250),
                'source' => 'manual',
            ];
        }

        if ($content !== '') {
            return [
                'excerpt' => $this->clean_excerpt($content, 220),
                'source' => 'automatic',
            ];
        }

        return ['excerpt' => '', 'source' => 'empty'];
    }

    private function clean_excerpt(string $text, int $max_length): string
    {
        $text = $this->remove_technical_blocks($text);
        $text = $this->normalize_avada_shortcodes($text);
        $text = $this->remove_remaining_shortcode_artifacts($text);
        $text = html_entity_decode(wp_strip_all_tags($text, true), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $max_length) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $max_length + 1);
        $last_space = mb_strrpos($truncated, ' ');
        if (false !== $last_space && $last_space > 120) {
            $truncated = mb_substr($truncated, 0, $last_space);
        } else {
            $truncated = mb_substr($text, 0, $max_length);
        }

        return rtrim($truncated, " \t\n\r\0\x0B.,;:!?") . '…';
    }

    private function remove_technical_blocks(string $content): string
    {
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $content);
        $content = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', (string) $content);
        $content = preg_replace('/<!--.*?-->/s', ' ', (string) $content);
        return (string) $content;
    }

    private function normalize_avada_shortcodes(string $content): string
    {
        $text_wrappers = ['fusion_text', 'fusion_title', 'fusion_builder_column', 'fusion_builder_row', 'fusion_builder_container', 'fusion_tab', 'fusion_toggle', 'fusion_alert'];
        foreach ($text_wrappers as $tag) {
            $content = preg_replace('/\[' . $tag . '\b[^\]]*\]/iu', "\n", (string) $content);
            $content = preg_replace('/\[\/' . $tag . '\]/iu', "\n", (string) $content);
        }

        $layout_shortcodes = ['fusion_separator', 'fusion_imageframe', 'fusion_image', 'fusion_gallery', 'fusion_code', 'fusion_global', 'fusion_menu_anchor', 'fusion_button'];
        foreach ($layout_shortcodes as $tag) {
            $content = preg_replace('/\[' . $tag . '\b[^\]]*\](?:.*?\[\/' . $tag . '\])?/isu', ' ', (string) $content);
            $content = preg_replace('/\[' . $tag . '\b[^\]]*\/\]/iu', ' ', (string) $content);
        }

        return (string) $content;
    }

    private function preserve_shortcode_inner_text(string $content): string
    {
        // Remove shortcode delimiters and attributes while leaving enclosed editorial text in place.
        $content = preg_replace('/\[\/?[a-zA-Z0-9_:-]+(?:\s+[^\]]*)?\]/u', ' ', $content);
        return (string) $content;
    }

    private function remove_remaining_shortcode_artifacts(string $content): string
    {
        $content = preg_replace('/\[[^\]]*\]/u', ' ', $content);
        $content = preg_replace('/\{\{[^}]*\}\}/u', ' ', (string) $content);
        return (string) $content;
    }
}
