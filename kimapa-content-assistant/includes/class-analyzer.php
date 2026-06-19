<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Analyzer
{
    /** @var Config */
    private $config;

    /** @var Content_Extractor */
    private $content_extractor;

    public function __construct(Config $config, Content_Extractor $content_extractor)
    {
        $this->config = $config;
        $this->content_extractor = $content_extractor;
    }

    public function analyze(int $post_id): array
    {
        $post = get_post($post_id);
        if (!$post) {
            return ['score' => 0, 'label' => __('Beitrag nicht gefunden', 'kimapa-content-assistant'), 'checks' => [], 'extracted_facts' => []];
        }

        $extraction = $this->content_extractor->extract($post_id);
        $categories = $this->category_names($post_id);
        $facts = $this->extract_facts($post, $extraction, $categories);
        $checks = array_merge($this->wordpress_checks($post, $extraction, $facts), $this->editorial_checks($post, $extraction, $facts), $this->consistency_checks($post, $extraction, $facts));
        $score = $this->calculate_score($checks);

        return [
            'score' => $score,
            'label' => $this->score_label($score),
            'checks' => $checks,
            'extracted_facts' => $facts,
            'advertising_disclosure_detected' => (bool) $facts['advertising_disclosure_detected'],
            'detected_relative_time_terms' => $facts['detected_relative_time_terms'],
            'detected_outdated_terms' => $facts['detected_outdated_terms'],
            'dates_without_year' => $facts['dates_without_year'],
            'placeholder_excerpt_detected' => (bool) $facts['placeholder_excerpt_detected'],
        ];
    }

    private function wordpress_checks(\WP_Post $post, array $extraction, array $facts): array
    {
        $post_id = (int) $post->ID;
        $content = (string) $extraction['content'];
        $word_count = (int) $extraction['content_word_count'];
        $thumb_id = get_post_thumbnail_id($post_id);
        $image = $thumb_id ? wp_get_attachment_image_src($thumb_id, 'full') : false;
        $min_words = (int) $this->config->get('wordpress_checks.min_word_count', 450);
        $min_width = (int) $this->config->get('wordpress_checks.featured_image_min_width', 1200);
        $min_height = (int) $this->config->get('wordpress_checks.featured_image_min_height', 800);
        $stale_days = (int) $this->config->get('wordpress_checks.stale_after_days', 365);
        $modified = get_post_modified_time('U', true, $post);
        $relative_terms = $facts['detected_relative_time_terms'];
        $featured_image_size_ok = $image && (int) $image[1] >= $min_width && (int) $image[2] >= $min_height;

        return [
            $this->check('featured_image', (bool) $thumb_id, __('Featured Image vorhanden', 'kimapa-content-assistant'), __('Bitte ein Beitragsbild setzen.', 'kimapa-content-assistant'), 12, 'error'),
            $this->check('featured_image_size', $featured_image_size_ok, sprintf(__('Featured Image mindestens %1$dx%2$d px', 'kimapa-content-assistant'), $min_width, $min_height), $thumb_id ? __('Featured Image vorhanden, aber für Social Media eventuell zu klein. Bitte größeres Bild prüfen.', 'kimapa-content-assistant') : __('Bitte ein ausreichend großes Beitragsbild setzen.', 'kimapa-content-assistant'), 8, 'warning'),
            $this->check('featured_image_alt', $thumb_id && trim((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true)) !== '', __('ALT-Text für Featured Image vorhanden', 'kimapa-content-assistant'), __('ALT-Text für Barrierefreiheit und SEO ergänzen.', 'kimapa-content-assistant'), 8, 'warning'),
            $this->check('excerpt', has_excerpt($post_id), __('Excerpt vorhanden', 'kimapa-content-assistant'), __('Kurzbeschreibung für Teaser und Newsletter ergänzen.', 'kimapa-content-assistant'), 8, 'warning'),
            $this->check('word_count', $word_count >= $min_words, sprintf(__('Beitragslänge ausreichend (%d Wörter)', 'kimapa-content-assistant'), $word_count), sprintf(__('Mindestens %d Wörter empfohlen.', 'kimapa-content-assistant'), $min_words), 8, 'warning'),
            $this->check('categories', count(wp_get_post_categories($post_id)) > 0, __('Kategorien vorhanden', 'kimapa-content-assistant'), __('Mindestens eine Kategorie auswählen.', 'kimapa-content-assistant'), 8, 'error'),
            $this->check('tags', count(wp_get_post_tags($post_id)) > 0, __('Tags vorhanden', 'kimapa-content-assistant'), __('Tags für Wiederverwertung und Themencluster ergänzen.', 'kimapa-content-assistant'), 5, 'warning'),
            $this->check('internal_links', $this->has_internal_links($post->post_content), __('Interne Links vorhanden', 'kimapa-content-assistant'), __('Interne KiMaPa-Verlinkungen ergänzen.', 'kimapa-content-assistant'), 7, 'warning'),
            $this->check('headings', preg_match('/<h[2-4][^>]*>/i', $post->post_content) === 1, __('Zwischenüberschriften vorhanden', 'kimapa-content-assistant'), __('Struktur mit H2/H3-Überschriften verbessern.', 'kimapa-content-assistant'), 6, 'warning'),
            $this->check('freshness', !$modified || ((time() - $modified) / DAY_IN_SECONDS) <= $stale_days, __('Aktualität im Schwellenwert', 'kimapa-content-assistant'), sprintf(__('Letzte Aktualisierung ist älter als %d Tage.', 'kimapa-content-assistant'), $stale_days), 8, 'warning'),
            $this->check('relative_time_terms', count($relative_terms) === 0, __('Keine relativen Zeitbegriffe gefunden', 'kimapa-content-assistant'), count($relative_terms) > 0 ? sprintf(__('Relative Zeitbegriffe gefunden: %s. Bitte nach Möglichkeit durch konkrete Daten oder Jahresangaben ersetzen.', 'kimapa-content-assistant'), implode(', ', $relative_terms)) : __('Keine relativen Zeitbegriffe gefunden.', 'kimapa-content-assistant'), 6, 'notice', ['relative_time_terms_found' => $relative_terms]),
        ];
    }

    private function editorial_checks(\WP_Post $post, array $extraction, array $facts): array
    {
        $checks = [];
        $checks[] = $this->check('placeholder_excerpt', !$facts['placeholder_excerpt_detected'], __('Auszug wirkt redaktionell', 'kimapa-content-assistant'), __('Der Auszug wirkt wie ein Platzhalter und sollte redaktionell ersetzt werden.', 'kimapa-content-assistant'), 5, 'warning', ['placeholder_excerpt_detected' => (bool) $facts['placeholder_excerpt_detected']]);
        $checks[] = $this->check('potentially_outdated_covid_content', count($facts['detected_outdated_terms']) === 0, __('Keine möglichen veralteten Corona-/Hygiene-Hinweise gefunden', 'kimapa-content-assistant'), count($facts['detected_outdated_terms']) > 0 ? __('Der Beitrag enthält mögliche veraltete Corona-/Hygiene-Hinweise. Bitte redaktionell prüfen.', 'kimapa-content-assistant') : __('Keine potenziell veralteten Corona-/Hygiene-Hinweise gefunden.', 'kimapa-content-assistant'), 5, 'warning', ['detected_outdated_terms' => $facts['detected_outdated_terms']]);
        $checks[] = $this->check('advertising_disclosure', true, __('Werbekennzeichnung', 'kimapa-content-assistant'), $facts['advertising_disclosure_detected'] ? __('Werbekennzeichnung erkannt. Bitte sicherstellen, dass die Kennzeichnung auch in Social-Media-Ausgaben sichtbar bleibt.', 'kimapa-content-assistant') : __('Keine Werbekennzeichnung erkannt.', 'kimapa-content-assistant'), 0, 'notice', ['advertising_disclosure_detected' => (bool) $facts['advertising_disclosure_detected']]);
        $structured_enabled = (bool) $this->config->get('structured_fields.enabled', false);
        if ($structured_enabled) {
            $has_any_location_field = $facts['latitude'] !== '' || $facts['longitude'] !== '' || $facts['street'] !== '' || $facts['zip'] !== '' || $facts['city'] !== '' || $facts['google_maps_link'] !== '';
            $checks[] = $this->check('structured_location_fields_available', $has_any_location_field, __('Strukturierte Standortfelder', 'kimapa-content-assistant'), $has_any_location_field ? __('Strukturierte Standortfelder erkannt.', 'kimapa-content-assistant') : __('Strukturierte Standortfelder sind aktiviert, aber leer.', 'kimapa-content-assistant'), 0, 'notice');
            $address_complete = $facts['city'] !== '' || ($facts['zip'] !== '' && $facts['city'] !== '') || ($facts['street'] !== '' && $facts['zip'] !== '' && $facts['city'] !== '');
            $checks[] = $this->check('structured_address_complete', $address_complete, __('Strukturierte Adresse', 'kimapa-content-assistant'), $address_complete ? __('Verwertbare strukturierte Adresse vorhanden.', 'kimapa-content-assistant') : __('Keine verwertbare strukturierte Adresse vorhanden.', 'kimapa-content-assistant'), 0, $address_complete ? 'notice' : 'warning');
            $checks[] = $this->check('coordinates_available', $facts['latitude'] !== '' && $facts['longitude'] !== '', __('Koordinaten', 'kimapa-content-assistant'), ($facts['latitude'] !== '' && $facts['longitude'] !== '') ? __('Koordinaten vorhanden.', 'kimapa-content-assistant') : __('Keine vollständigen Koordinaten vorhanden.', 'kimapa-content-assistant'), 0, 'notice');
            $checks[] = $this->check('external_link_available', $facts['external_link'] !== '', __('Externer Link', 'kimapa-content-assistant'), $facts['external_link'] !== '' ? __('Externer Link vorhanden.', 'kimapa-content-assistant') : __('Kein externer Link erkannt.', 'kimapa-content-assistant'), 0, 'notice');
            $checks[] = $this->check('image_credit_available', $facts['image_credit'] !== '', __('Bildquelle', 'kimapa-content-assistant'), $facts['image_credit'] !== '' ? __('Bildquelle vorhanden.', 'kimapa-content-assistant') : __('Keine Bildquelle erkannt.', 'kimapa-content-assistant'), 0, 'notice');
        }
        $checks[] = $this->check('dates_without_year', count($facts['dates_without_year']) === 0, __('Datumsangaben mit Jahresbezug', 'kimapa-content-assistant'), count($facts['dates_without_year']) > 0 ? __('Datumsangaben ohne Jahr gefunden. Bitte prüfen, ob für langfristige Aktualität eine Jahresangabe ergänzt werden sollte.', 'kimapa-content-assistant') : __('Keine Datumsangaben ohne Jahr gefunden.', 'kimapa-content-assistant'), 4, 'notice', ['dates_without_year' => $facts['dates_without_year']]);
        return $checks;
    }

    private function consistency_checks(\WP_Post $post, array $extraction, array $facts): array
    {
        $content = $extraction['content'] . ' ' . $extraction['excerpt'];
        $items = (array) $this->config->get('content_consistency_checks', []);
        $checks = [];
        foreach ($items as $key => $item) {
            $terms = $item['keywords'] ?? [];
            $passed = count($this->find_terms($content, (array) $terms)) > 0;
            $message = $item['hint'] ?? __('Bitte redaktionell prüfen.', 'kimapa-content-assistant');
            if ((string) $key === 'weather' && $facts['indoor_outdoor'] === 'indoor') {
                $passed = true;
                $message = __('Indoor-/Schlechtwetter-Eignung erkannt.', 'kimapa-content-assistant');
            }
            $checks[] = $this->check('consistency_' . sanitize_key((string) $key), $passed, $item['label'] ?? (string) $key, $message, (int) ($item['weight'] ?? 2), 'notice');
        }
        return $checks;
    }

    public function extract_facts(\WP_Post $post, array $extraction, array $categories = []): array
    {
        $title = get_the_title($post);
        $content = (string) $extraction['content'];
        $haystack = trim($title . "\n" . $content . "\n" . (string) $extraction['excerpt']);
        $category_text = implode(' ', $categories);
        $relative_terms = (array) $this->config->get('wordpress_checks.relative_time_terms', []);
        $outdated_terms = ['Corona', 'Pandemie', 'Hygiene- und Sicherheitskonzept', 'aufgrund der Auflagen', 'derzeit geschlossen', 'reduzierte Plätze', 'ohne Pause durchgespielt', 'Schutzmaßnahmen'];
        $advertising_terms = ['#Anzeige', 'Anzeige', 'Werbung', 'Advertorial', 'Sponsored', 'Kooperation'];
        $structured = $this->get_structured_facts_from_meta((int) $post->ID);
        $fallback = !empty($structured['fallback_to_content_parsing']);
        $parsed_address = $fallback ? $this->detect_address($content) : '';
        $parsed_location_from_address = $parsed_address ? $this->location_from_address($parsed_address) : '';
        $region = $fallback ? $this->detect_region($haystack, $structured['city'] ?: $parsed_location_from_address) : '';
        $parsed_location = $fallback ? ($parsed_location_from_address ?: $this->detect_location($haystack, $category_text, $region)) : '';
        $address = $structured['address'] ?: $parsed_address;
        $location = $structured['city'] ?: $parsed_location;
        $age = $this->detect_age_recommendation($haystack);
        $price = $this->detect_price_or_offer($haystack);
        $hours = $this->detect_opening_hours_or_dates($haystack);
        $facts_source = [
            'location' => $structured['city'] !== '' ? 'custom_field' : ($parsed_location !== '' ? 'content_parsing' : 'empty'),
            'address' => $structured['address'] !== '' ? 'custom_field' : ($parsed_address !== '' ? 'content_parsing' : 'empty'),
            'coordinates' => ($structured['latitude'] !== '' && $structured['longitude'] !== '') ? 'custom_field' : 'empty',
            'external_link' => $structured['external_link'] !== '' ? 'custom_field' : 'empty',
            'image_credit' => $structured['image_credit'] !== '' ? 'custom_field' : 'empty',
        ];

        return [
            'location' => $location,
            'region_or_nearby' => $region,
            'address' => $address,
            'street' => $structured['street'],
            'zip' => $structured['zip'],
            'city' => $structured['city'],
            'latitude' => $structured['latitude'],
            'longitude' => $structured['longitude'],
            'google_maps_link' => $structured['google_maps_link'],
            'external_link' => $structured['external_link'],
            'image_credit' => $structured['image_credit'],
            'age_recommendation' => $age,
            'price_or_offer' => $price,
            'opening_hours_or_dates' => $hours,
            'indoor_outdoor' => $this->detect_indoor_outdoor($haystack, $category_text),
            'advertising_disclosure_detected' => count($this->find_terms($haystack, $advertising_terms)) > 0,
            'detected_relative_time_terms' => $this->find_terms($haystack, $relative_terms),
            'detected_outdated_terms' => $this->find_terms($haystack, $outdated_terms),
            'dates_without_year' => $this->find_dates_without_year($haystack),
            'placeholder_excerpt_detected' => $this->is_placeholder_excerpt((string) $extraction['excerpt'], $title, $content),
            'facts_source' => $facts_source,
            'extracted_fact_confidence' => [
                'location' => $location !== '' ? ($facts_source['location'] === 'custom_field' ? 'high' : 'medium') : '',
                'address' => $address !== '' ? ($facts_source['address'] === 'custom_field' ? 'high' : 'medium') : '',
                'age_recommendation' => $age !== '' ? 'medium' : '',
                'price_or_offer' => $price !== '' ? 'medium' : '',
                'opening_hours_or_dates' => $hours !== '' ? 'medium' : '',
            ],
        ];
    }

    public function get_structured_facts_from_meta(int $post_id): array
    {
        $meta_keys = (array) $this->config->get('structured_fields.meta_keys', []);
        $enabled = (bool) $this->config->get('structured_fields.enabled', false);
        $fallback = (bool) $this->config->get('structured_fields.fallback_to_content_parsing', true);
        $facts = [
            'enabled' => $enabled,
            'fallback_to_content_parsing' => $fallback,
            'latitude' => '',
            'longitude' => '',
            'google_maps_link' => '',
            'street' => '',
            'zip' => '',
            'city' => '',
            'external_link' => '',
            'image_credit' => '',
            'address' => '',
        ];
        if (!$enabled) {
            return $facts;
        }
        foreach (['latitude', 'longitude', 'google_maps_link', 'street', 'zip', 'city', 'external_link', 'image_credit'] as $field) {
            $key = isset($meta_keys[$field]) ? trim((string) $meta_keys[$field]) : '';
            if ($key === '') {
                continue;
            }
            $raw = get_post_meta($post_id, $key, true);
            if (is_array($raw) || is_object($raw)) {
                $raw = wp_json_encode($raw);
            }
            $facts[$field] = $this->sanitize_structured_value($field, (string) $raw);
        }
        if ($facts['street'] !== '' && $facts['zip'] !== '' && $facts['city'] !== '') {
            $facts['address'] = $facts['street'] . ', ' . $facts['zip'] . ' ' . $facts['city'];
        } elseif ($facts['zip'] !== '' && $facts['city'] !== '') {
            $facts['address'] = $facts['zip'] . ' ' . $facts['city'];
        }
        return $facts;
    }

    private function sanitize_structured_value(string $field, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (in_array($field, ['google_maps_link', 'external_link'], true)) {
            return esc_url_raw($value);
        }
        if (in_array($field, ['latitude', 'longitude'], true)) {
            return preg_match('/^-?\d{1,3}(?:\.\d+)?$/', $value) ? $value : '';
        }
        if ($field === 'zip') {
            return preg_match('/^\d{5}$/', $value) ? $value : sanitize_text_field($value);
        }
        return sanitize_text_field($value);
    }

    private function detect_address(string $content): string
    {
        $street = '(?:Straße|str\.|Str\.|Weg|Platz|Allee|Gasse|Ring|Ufer)';
        $lines = preg_split('/\R/u', $content) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/(?:Adresse(?: Parkplatz)?|Anfahrt|Ort|Treffpunkt)\s*:?\s*(.+)/iu', $line, $m)) {
                $candidate = trim($m[1]);
                if (preg_match('/\b\d{5}\b/u', $candidate) && preg_match('/\b[\p{L}ÄÖÜäöüß\- ]+' . $street . '\s+\d+[a-zA-Z]?/iu', $candidate)) {
                    return $this->clean_fact($candidate);
                }
            }
        }
        if (preg_match('/\b([\p{L}ÄÖÜäöüß\- ]+' . $street . '\s+\d+[a-zA-Z]?,?\s*\d{5}\s+[A-ZÄÖÜ][\p{L}ÄÖÜäöüß\- ]+)\b/iu', $content, $m)) {
            return $this->clean_fact($m[1]);
        }
        return '';
    }

    private function location_from_address(string $address): string
    {
        if (preg_match('/\b\d{5}\s+([A-ZÄÖÜ][\p{L}ÄÖÜäöüß\- ]+)$/u', $address, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function detect_region(string $content, string $location): string
    {
        if (preg_match('/(?:bei|rund um|von|nahe)\s+([A-ZÄÖÜ][\p{L}ÄÖÜäöüß\-]+)(?:\s+entfernt)?/u', $content, $m)) {
            $candidate = trim($m[1]);
            return $candidate !== $location && !$this->is_comparison_location($content, $candidate) ? $candidate : '';
        }
        if (preg_match('/nur\s+\d+\s+Autominuten\s+von\s+([A-ZÄÖÜ][\p{L}ÄÖÜäöüß\-]+)\s+entfernt/u', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function detect_location(string $content, string $category_text, string $region): string
    {
        if (preg_match('/\b(?:in|bei|rund um)\s+([A-ZÄÖÜ][\p{L}ÄÖÜäöüß\-]+)\b/u', $category_text, $m)) {
            return trim($m[1]);
        }
        preg_match_all('/\b(?:in|bei|rund um)\s+([A-ZÄÖÜ][\p{L}ÄÖÜäöüß\-]+)\b/u', $content, $matches);
        foreach ($matches[1] ?? [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== $region && !$this->is_bad_location_candidate($candidate) && !$this->is_comparison_location($content, $candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function is_bad_location_candidate(string $candidate): bool
    {
        return in_array(mb_strtolower($candidate), ['begleitung', 'athen'], true);
    }

    private function is_comparison_location(string $content, string $candidate): bool
    {
        return preg_match('/(?:erinnerte\s+an|ähnlich\s+wie|wie|Akropolis\s+in)\s+[^.\n]{0,80}' . preg_quote($candidate, '/') . '/iu', $content) === 1;
    }

    private function detect_opening_hours_or_dates(string $content): string
    {
        $weekday = '(?:Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag|Mo|Di|Mi|Do|Fr|Sa|So)';
        $time = '\d{1,2}(?::|\.)?\d{0,2}\s*Uhr';
        $date = '(?:vom\s+)?\d{1,2}\.\s*(?:Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)(?:\s+bis\s+\d{1,2}\.\s*(?:Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember))?';
        if (preg_match('/\b(' . $weekday . '[^.\n]{0,80}' . $time . '(?:[^.\n]{0,80}' . $time . ')?)\b/iu', $content, $m)) {
            return $this->clean_fact($m[1]);
        }
        if (preg_match('/\b(' . $date . ')\b/iu', $content, $m)) {
            return $this->clean_fact($m[1]);
        }
        if (preg_match('/\b(Öffnungszeiten[^.\n]{0,140})/iu', $content, $m)) {
            return $this->clean_fact($m[1]);
        }
        return '';
    }

    private function detect_price_or_offer(string $content): string
    {
        if (preg_match('/\b(ein\s+Kind\s+bis\s+14\s+Jahre\s+in\s+Begleitung\s+eines\s+regulär\s+zahlenden\s+Erwachsenen\s+kostenlos)\b/iu', $content, $m)) {
            return ucfirst($this->clean_fact($m[1]));
        }
        if (preg_match('/\b([^\n.]{0,60}(?:kostenlos|freier Eintritt|Eintritt frei|Kind kostenlos|Rabatt|Ermäßigung)[^\n.]{0,60})\b/iu', $content, $m)) {
            return $this->clean_fact($m[1]);
        }
        if (preg_match('/\b(\d+[,.]?\d*\s*(?:€|Euro))\b/iu', $content, $m)) {
            return $this->clean_fact($m[1]);
        }
        return '';
    }

    private function detect_age_recommendation(string $content): string
    {
        if (preg_match('/\b(ab\s+(?:\d+|[a-zäöüß]+)\s+Jahren|für\s+Kinder\s+ab\s+[^,.\n]+|bis\s+\d+\s+Jahre|Kleinkinder|Grundschulkinder|Teenager)\b/iu', $content, $m)) {
            return $this->clean_fact($m[1]);
        }
        return '';
    }

    private function clean_fact(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value), " \t\n\r\0\x0B.,;:");
    }

    private function is_placeholder_excerpt(string $excerpt, string $title, string $content): bool
    {
        $excerpt = trim($excerpt);
        if ($excerpt === '') {
            return false;
        }

        $placeholder_terms = ['und hier steht der Auszugstext', 'Lorem ipsum', 'Hier steht der Auszug', 'Dieser soll dem User einen schnellen Ein- und Überblick geben'];
        if (count($this->find_terms($excerpt, $placeholder_terms)) > 0) {
            return true;
        }

        $word_count = $this->content_extractor->word_count($excerpt);
        if ($word_count < 8) {
            return false;
        }

        $title_words = $this->significant_words($title);
        $excerpt_words = $this->significant_words($excerpt);
        $content_words = array_slice($this->significant_words($content), 0, 80);
        $overlap = array_intersect($excerpt_words, array_unique(array_merge($title_words, $content_words)));

        return $word_count <= 18 && count($overlap) === 0;
    }

    private function find_dates_without_year(string $content): array
    {
        preg_match_all('/\b\d{1,2}\.\s*(?:Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)(?:\s+bis\s+\d{1,2}\.\s*(?:Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember))?/iu', $content, $matches, PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches[0] as $match) {
            $text = $match[0];
            $offset = (int) $match[1];
            $context = mb_substr($content, max(0, $offset - 20), mb_strlen($text) + 40);
            if (!preg_match('/\b20\d{2}\b/u', $context)) {
                $found[] = trim($text);
            }
        }
        return array_values(array_unique($found));
    }

    private function detect_indoor_outdoor(string $content, string $category_text): string
    {
        $combined = $category_text . ' ' . $content;
        if (count($this->find_terms($combined, ['Indoor', 'Theater', 'Museum', 'Kino', 'drinnen', 'Schlechtwetter'])) > 0) {
            return 'indoor';
        }
        if (count($this->find_terms($combined, ['Outdoor', 'draußen', 'Spielplatz', 'Park', 'Wanderung', 'See', 'Badesee'])) > 0) {
            return 'outdoor';
        }
        return '';
    }

    private function category_names(int $post_id): array
    {
        $terms = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
        return is_wp_error($terms) ? [] : $terms;
    }

    private function first_match(string $pattern, string $content): string
    {
        if (preg_match($pattern, $content, $matches)) {
            return trim((string) ($matches[1] ?? $matches[0]));
        }
        return '';
    }

    private function significant_words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($text), $matches);
        $stopwords = ['diese', 'dieser', 'dieses', 'einen', 'eine', 'einer', 'soll', 'user', 'schnellen', 'ueberblick', 'überblick', 'geben', 'steht', 'hier'];
        return array_values(array_diff(array_unique($matches[0]), $stopwords));
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

    private function check(string $key, bool $passed, string $label, string $message, int $weight, string $severity, array $data = []): array
    {
        return array_merge(compact('key', 'passed', 'label', 'message', 'weight', 'severity'), $data);
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
