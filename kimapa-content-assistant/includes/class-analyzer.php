<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Analyzer
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function analyze(int $post_id): array
    {
        $post = get_post($post_id);
        if (!$post) {
            return ['score' => 0, 'label' => __('Beitrag nicht gefunden', 'kimapa-content-assistant'), 'checks' => []];
        }

        $checks = array_merge($this->wordpress_checks($post), $this->consistency_checks($post));
        $score = $this->calculate_score($checks);

        return [
            'score' => $score,
            'label' => $this->score_label($score),
            'checks' => $checks,
        ];
    }

    private function wordpress_checks(\WP_Post $post): array
    {
        $post_id = (int) $post->ID;
        $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
        $word_count = str_word_count($content);
        $thumb_id = get_post_thumbnail_id($post_id);
        $image = $thumb_id ? wp_get_attachment_image_src($thumb_id, 'full') : false;
        $min_words = (int) $this->config->get('wordpress_checks.min_word_count', 450);
        $min_width = (int) $this->config->get('wordpress_checks.featured_image_min_width', 1200);
        $min_height = (int) $this->config->get('wordpress_checks.featured_image_min_height', 800);
        $stale_days = (int) $this->config->get('wordpress_checks.stale_after_days', 365);
        $modified = get_post_modified_time('U', true, $post);
        $relative_terms = (array) $this->config->get('wordpress_checks.relative_time_terms', ['aktuell', 'bald', 'dieses Jahr', 'demnächst']);

        return [
            $this->check('featured_image', (bool) $thumb_id, __('Featured Image vorhanden', 'kimapa-content-assistant'), __('Bitte ein Beitragsbild setzen.', 'kimapa-content-assistant'), 12, 'error'),
            $this->check('featured_image_size', $image && (int) $image[1] >= $min_width && (int) $image[2] >= $min_height, sprintf(__('Featured Image mindestens %1$dx%2$d px', 'kimapa-content-assistant'), $min_width, $min_height), __('Bildgröße für Social Media prüfen.', 'kimapa-content-assistant'), 8, 'warning'),
            $this->check('featured_image_alt', $thumb_id && trim((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true)) !== '', __('ALT-Text für Featured Image vorhanden', 'kimapa-content-assistant'), __('ALT-Text für Barrierefreiheit und SEO ergänzen.', 'kimapa-content-assistant'), 8, 'warning'),
            $this->check('excerpt', has_excerpt($post_id), __('Excerpt vorhanden', 'kimapa-content-assistant'), __('Kurzbeschreibung für Teaser und Newsletter ergänzen.', 'kimapa-content-assistant'), 8, 'warning'),
            $this->check('word_count', $word_count >= $min_words, sprintf(__('Beitragslänge ausreichend (%d Wörter)', 'kimapa-content-assistant'), $word_count), sprintf(__('Mindestens %d Wörter empfohlen.', 'kimapa-content-assistant'), $min_words), 8, 'warning'),
            $this->check('categories', count(wp_get_post_categories($post_id)) > 0, __('Kategorien vorhanden', 'kimapa-content-assistant'), __('Mindestens eine Kategorie auswählen.', 'kimapa-content-assistant'), 8, 'error'),
            $this->check('tags', count(wp_get_post_tags($post_id)) > 0, __('Tags vorhanden', 'kimapa-content-assistant'), __('Tags für Wiederverwertung und Themencluster ergänzen.', 'kimapa-content-assistant'), 5, 'warning'),
            $this->check('internal_links', $this->has_internal_links($post->post_content), __('Interne Links vorhanden', 'kimapa-content-assistant'), __('Interne KiMaPa-Verlinkungen ergänzen.', 'kimapa-content-assistant'), 7, 'warning'),
            $this->check('headings', preg_match('/<h[2-4][^>]*>/i', $post->post_content) === 1, __('Zwischenüberschriften vorhanden', 'kimapa-content-assistant'), __('Struktur mit H2/H3-Überschriften verbessern.', 'kimapa-content-assistant'), 6, 'warning'),
            $this->check('freshness', !$modified || ((time() - $modified) / DAY_IN_SECONDS) <= $stale_days, __('Aktualität im Schwellenwert', 'kimapa-content-assistant'), sprintf(__('Letzte Aktualisierung ist älter als %d Tage.', 'kimapa-content-assistant'), $stale_days), 8, 'warning'),
            $this->check('relative_time_terms', count($this->find_terms($content, $relative_terms)) === 0, __('Keine relativen Zeitbegriffe gefunden', 'kimapa-content-assistant'), sprintf(__('Relative Zeitbegriffe prüfen: %s', 'kimapa-content-assistant'), implode(', ', $this->find_terms($content, $relative_terms))), 6, 'notice'),
        ];
    }

    private function consistency_checks(\WP_Post $post): array
    {
        $content = wp_strip_all_tags(strip_shortcodes($post->post_content . ' ' . $post->post_excerpt));
        $items = (array) $this->config->get('content_consistency_checks', []);
        $checks = [];
        foreach ($items as $key => $item) {
            $terms = $item['keywords'] ?? [];
            $checks[] = $this->check('consistency_' . sanitize_key((string) $key), count($this->find_terms($content, (array) $terms)) > 0, $item['label'] ?? (string) $key, $item['hint'] ?? __('Bitte redaktionell prüfen.', 'kimapa-content-assistant'), (int) ($item['weight'] ?? 2), 'notice');
        }
        return $checks;
    }

    private function calculate_score(array $checks): int
    {
        $max = 0;
        $earned = 0;
        foreach ($checks as $check) {
            $weight = (int) ($check['weight'] ?? 0);
            $max += $weight;
            if (!empty($check['passed'])) {
                $earned += $weight;
            } elseif (($check['severity'] ?? '') === 'notice') {
                $earned += (int) floor($weight / 2);
            }
        }
        return $max > 0 ? (int) round(($earned / $max) * 100) : 0;
    }

    private function score_label(int $score): string
    {
        foreach ((array) $this->config->get('scoring.labels', []) as $label) {
            if ($score >= (int) ($label['min'] ?? 0) && $score <= (int) ($label['max'] ?? 100)) {
                return (string) ($label['text'] ?? '');
            }
        }
        return __('Bewertung berechnet', 'kimapa-content-assistant');
    }

    private function check(string $key, bool $passed, string $label, string $message, int $weight, string $severity): array
    {
        return compact('key', 'passed', 'label', 'message', 'weight', 'severity');
    }

    private function find_terms(string $content, array $terms): array
    {
        $found = [];
        foreach ($terms as $term) {
            if ($term !== '' && mb_stripos($content, (string) $term) !== false) {
                $found[] = (string) $term;
            }
        }
        return array_values(array_unique($found));
    }

    private function has_internal_links(string $content): bool
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return preg_match('/<a\s[^>]*href=["\'](?:\/|https?:\/\/' . preg_quote((string) $host, '/') . ')/i', $content) === 1;
    }
}
