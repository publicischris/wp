<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Prompt_Builder
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

    public function build(int $post_id, array $analysis): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $categories = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $extraction = $this->content_extractor->extract($post_id);
        $extracted_facts = $analysis['extracted_facts'] ?? [];
        $content_is_incomplete = in_array($extraction['content_extraction_status'], ['empty', 'short'], true);
        $advertising_detected = !empty($extracted_facts['advertising_disclosure_detected']);
        $placeholder_excerpt = !empty($extracted_facts['placeholder_excerpt_detected']);
        $dates_without_year = !empty($extracted_facts['dates_without_year']);
        $structured_fields_enabled = (bool) $this->config->get('structured_fields.enabled', false);
        $has_coordinates = !empty($extracted_facts['latitude']) && !empty($extracted_facts['longitude']);
        $has_address = !empty($extracted_facts['address']);
        $has_image_credit = !empty($extracted_facts['image_credit']);
        $has_internal_notes = !empty($extracted_facts['internal_editorial_notes_detected']);

        $payload = [
            'role' => sprintf('Du bist ein redaktioneller Content Assistant für %s.', (string) $this->config->get('general.brand_name', 'KiMaPa')),
            'project_context' => [
                'plugin_name' => $this->config->get('general.plugin_name', 'KiMaPa Content Assistant'),
                'brand_name' => $this->config->get('general.brand_name', 'KiMaPa'),
                'portal_description' => $this->config->get('general.portal_description', ''),
                'language' => $this->config->get('general.language', 'de'),
                'active_channels' => array_keys(array_filter($this->config->channels())),
            ],
            'task' => 'Analysiere den Beitrag und erstelle strukturierte Vorschläge für die aktivierten Kanäle. Veröffentliche nichts automatisch.',
            'input' => [
                'title' => get_the_title($post),
                'permalink' => get_permalink($post),
                'excerpt' => $extraction['excerpt'],
                'content' => $this->limit_content($extraction['content']),
                'content_word_count' => $extraction['content_word_count'],
                'content_extraction_status' => $extraction['content_extraction_status'],
                'content_extraction_warnings' => $extraction['content_extraction_warnings'],
                'categories' => is_wp_error($categories) ? [] : $categories,
                'tags' => is_wp_error($tags) ? [] : $tags,
                'checks' => $analysis['checks'] ?? [],
                'extracted_facts' => $extracted_facts,
            ],
            'brand_guidance' => [
                'tone' => $this->config->get('brand_guidance.tone', $this->config->get('tone', [])),
                'avoid_phrases' => $this->config->get('brand_guidance.avoid_phrases', $this->config->get('avoid_phrases', [])),
            ],
            'editorial_rules' => $this->config->get('editorial_rules', []),
            'required_output' => $this->config->required_output(),
            'conditional_instructions' => array_values(array_filter([
                $content_is_incomplete ? 'Wenn excerpt und content leer oder unvollständig sind, darfst du keine konkreten Details erfinden. Erstelle nur allgemeine Vorschläge auf Basis von Titel, Kategorie und Checks und weise deutlich darauf hin, dass der Beitragstext fehlt.' : '',
                $advertising_detected ? 'Werbekennzeichnung wurde erkannt. Entferne sie nicht; halte sie in Instagram Caption und Newsletter-Hinweisen sichtbar.' : '',
                $placeholder_excerpt ? 'Der aktuelle Auszug wirkt wie ein Platzhalter. Schlage einen neuen redaktionellen Excerpt vor.' : '',
                $dates_without_year ? 'Datumsangaben ohne Jahr wurden erkannt. Markiere dies als redaktionellen Verbesserungshinweis.' : '',
                $structured_fields_enabled ? 'Strukturierte Felder wie Adresse, Koordinaten, Google Maps Link, externer Link und Bildquelle sind gegenüber unsicherem Content-Parsing zu bevorzugen.' : '',
                ($has_coordinates && !$has_address) ? 'Koordinaten vorhanden, Adresse nicht angegeben. Erfinde keine Adresse aus Koordinaten.' : '',
                $has_image_credit ? 'Bildquelle ist als redaktioneller Fact vorhanden, aber nicht automatisch in Instagram Caption einbauen, außer ausdrücklich gewünscht.' : '',
                $has_internal_notes ? 'Interne Redaktionsnotizen oder unfertige Kommentarstellen wurden erkannt. Diese dürfen nicht in Social-Media- oder Newsletter-Texte übernommen werden. Weise in den redaktionellen Verbesserungshinweisen konkret darauf hin.' : '',
            ])),
            'format' => $this->config->get('format_settings', $this->config->get('output_formats', [])),
        ];

        return wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function limit_content(string $content): string
    {
        return trim(mb_substr($content, 0, 12000));
    }
}
