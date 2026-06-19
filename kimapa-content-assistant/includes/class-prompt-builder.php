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

        $language = (string) $this->config->get('general.language', 'de');
        $texts = $this->prompt_texts($language);
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
            'role' => sprintf($texts['role'], (string) $this->config->get('general.brand_name', 'Your Brand')),
            'project_context' => [
                'plugin_name' => $this->config->get('general.plugin_name', 'Content Assistant'),
                'brand_name' => $this->config->get('general.brand_name', 'Your Brand'),
                'portal_description' => $this->config->get('general.portal_description', ''),
                'language' => $language,
                'content_profile' => $this->config->get('general.content_profile', 'generic_editorial'),
                'active_channels' => array_keys(array_filter($this->config->channels())),
            ],
            'task' => $texts['task'],
            'output_language_instruction' => sprintf($texts['output_language'], $language),
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
            'active_consistency_checks' => array_values((array) $this->config->get('content_consistency_checks', [])),
            'brand_guidance' => [
                'tone' => $this->config->get('brand_guidance.tone', $this->config->get('tone', [])),
                'avoid_phrases' => $this->config->get('brand_guidance.avoid_phrases', $this->config->get('avoid_phrases', [])),
            ],
            'editorial_rules' => $this->localized_editorial_rules($texts),
            'required_output' => $this->config->required_output(),
            'conditional_instructions' => array_values(array_filter([
                $content_is_incomplete ? $texts['content_incomplete'] : '',
                $advertising_detected ? $texts['advertising_detected'] : '',
                $placeholder_excerpt ? $texts['placeholder_excerpt'] : '',
                $dates_without_year ? $texts['dates_without_year'] : '',
                $structured_fields_enabled ? $texts['structured_fields'] : '',
                ($has_coordinates && !$has_address) ? $texts['coordinates_no_address'] : '',
                $has_image_credit ? $texts['image_credit'] : '',
                $has_internal_notes ? $texts['internal_notes'] : '',
            ])),
            'format' => $this->config->get('format_settings', $this->config->get('output_formats', [])),
        ];

        return wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function prompt_texts(string $language): array
    {
        if (strpos(strtolower($language), 'en') === 0) {
            return [
                'role' => 'You are an editorial content assistant for %s.',
                'task' => 'Analyze the content and create structured suggestions for the active channels. Do not publish anything automatically.',
                'output_language' => 'Write all generated output and editorial guidance in %s.',
                'no_fabrication' => 'Do not invent facts.',
                'content_incomplete' => 'If excerpt and content are empty or incomplete, do not invent concrete details. Create only general suggestions based on title, categories and checks, and clearly state that the article text is missing.',
                'advertising_detected' => 'Advertising disclosure was detected. Do not remove it; keep #Anzeige or an appropriate disclosure visible in social media and newsletter suggestions.',
                'placeholder_excerpt' => 'The current excerpt appears to be a placeholder. Suggest a new editorial excerpt.',
                'dates_without_year' => 'Dates without a year were detected. Mark this as an editorial improvement note.',
                'structured_fields' => 'Structured fields such as address, coordinates, Google Maps link, external link and image credit should be preferred over uncertain content parsing.',
                'coordinates_no_address' => 'Coordinates are available but no address was provided. Do not invent an address from coordinates.',
                'image_credit' => 'Image credit is available as an editorial fact, but do not automatically include it in Instagram captions unless explicitly requested.',
                'internal_notes' => 'Internal editorial notes or unfinished draft comments were detected. Do not reuse them in social media or newsletter copy. Point them out specifically in the editorial improvement notes.',
                'default_rules' => [
                    'Do not invent facts.',
                    'Only use information that is present in the article, categories, tags, checks or extracted_facts.',
                    'If content appears outdated, clearly mark this in the editorial improvement notes.',
                    'If advertising disclosure was detected, it must not be removed from social media or newsletter outputs.',
                    'If the excerpt appears to be a placeholder, suggest a new editorial excerpt.',
                    'If dates without a year were detected, mention this as an improvement note.',
                ],
            ];
        }

        return [
            'role' => 'Du bist ein redaktioneller Content Assistant für %s.',
            'task' => 'Analysiere den Beitrag und erstelle strukturierte Vorschläge für die aktivierten Kanäle. Veröffentliche nichts automatisch.',
            'output_language' => 'Schreibe alle generierten Ausgaben und redaktionellen Hinweise in %s.',
            'no_fabrication' => 'Keine Fakten erfinden.',
            'content_incomplete' => 'Wenn Auszug und Inhalt leer oder unvollständig sind, darfst du keine konkreten Details erfinden. Erstelle nur allgemeine Vorschläge auf Basis von Titel, Kategorien und Checks und weise deutlich darauf hin, dass der Beitragstext fehlt.',
            'advertising_detected' => 'Werbekennzeichnung wurde erkannt. Entferne sie nicht; halte #Anzeige oder eine geeignete Kennzeichnung in Social-Media- und Newsletter-Vorschlägen sichtbar.',
            'placeholder_excerpt' => 'Der aktuelle Auszug wirkt wie ein Platzhalter. Schlage einen neuen redaktionellen Auszug vor.',
            'dates_without_year' => 'Datumsangaben ohne Jahr wurden erkannt. Markiere dies als redaktionellen Verbesserungshinweis.',
            'structured_fields' => 'Strukturierte Felder wie Adresse, Koordinaten, Google Maps Link, externer Link und Bildquelle sind gegenüber unsicherem Content-Parsing zu bevorzugen.',
            'coordinates_no_address' => 'Koordinaten vorhanden, Adresse nicht angegeben. Erfinde keine Adresse aus Koordinaten.',
            'image_credit' => 'Bildquelle ist als redaktioneller Fakt vorhanden, aber nicht automatisch in Instagram Caption einbauen, außer ausdrücklich gewünscht.',
            'internal_notes' => 'Interne Notizen oder unfertige Stellen wurden erkannt. Diese dürfen nicht in Social-Media- oder Newsletter-Texte übernommen werden. Weise in den redaktionellen Verbesserungshinweisen konkret darauf hin.',
            'default_rules' => [
                'Keine Fakten erfinden.',
                'Nur Informationen verwenden, die im Beitrag, in Kategorien, Tags, Checks oder extracted_facts vorhanden sind.',
                'Wenn Inhalte veraltet wirken, in den redaktionellen Hinweisen deutlich markieren.',
                'Wenn Werbekennzeichnung erkannt wurde, darf sie in Social-Media- oder Newsletter-Ausgaben nicht entfernt werden.',
                'Wenn der Auszug wie ein Platzhalter wirkt, einen neuen redaktionellen Auszug vorschlagen.',
                'Wenn Datumsangaben ohne Jahr gefunden wurden, dies als Verbesserungshinweis aufnehmen.',
            ],
        ];
    }

    private function localized_editorial_rules(array $texts): array
    {
        $configured = (array) $this->config->get('editorial_rules', []);
        if (strpos(strtolower((string) $this->config->get('general.language', 'de')), 'en') === 0) {
            return $texts['default_rules'];
        }
        return $configured ?: $texts['default_rules'];
    }

    private function limit_content(string $content): string
    {
        return trim(mb_substr($content, 0, 12000));
    }
}
