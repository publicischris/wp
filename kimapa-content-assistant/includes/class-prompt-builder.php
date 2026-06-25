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

    /** @var Language_Resolver */
    private $language_resolver;

    public function __construct(Config $config, Content_Extractor $content_extractor, Language_Resolver $language_resolver)
    {
        $this->config = $config;
        $this->content_extractor = $content_extractor;
        $this->language_resolver = $language_resolver;
    }

    public function build(int $post_id, array $analysis): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $resolved_language = $this->language_resolver->resolve($post_id);
        $language = $resolved_language['language'];
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
                'language_source' => $resolved_language['source'],
                'content_profile' => $this->config->get('general.content_profile', 'generic_editorial'),
                'active_channels' => array_keys(array_filter($this->config->channels())),
            ],
            'task' => $texts['task'],
            'output_language_instruction' => $texts['output_language'],
            'ai_usage' => $this->ai_usage_context($language),
            'target_ai_provider' => $this->target_ai_provider(),
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
            'brand_guidance' => $this->localized_brand_guidance($language),
            'editorial_rules' => $this->localized_editorial_rules($texts, $language),
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



    private function ai_usage_context(string $language): array
    {
        $ai_usage = (array) $this->config->get('ai_usage', []);
        $mode = $this->allowed_value((string) ($ai_usage['ai_mode'] ?? 'manual'), ['manual', 'api_prepared_inactive'], 'manual');
        $provider = $this->allowed_value((string) ($ai_usage['preferred_ai_provider'] ?? 'generic'), array_keys($this->ai_provider_labels()), 'generic');
        $policy = $this->allowed_value((string) ($ai_usage['ai_usage_policy'] ?? 'not_documented'), array_keys($this->ai_policy_notices($language)), 'not_documented');
        $custom_note = trim((string) ($ai_usage['custom_ai_policy_note'] ?? ''));

        return [
            'mode' => $mode,
            'provider' => $provider,
            'policy' => $policy,
            'policy_notice' => $this->policy_notice($policy, $custom_note, $language),
        ];
    }

    private function target_ai_provider(): array
    {
        $provider = (string) $this->config->get('ai_usage.preferred_ai_provider', 'generic');
        $labels = $this->ai_provider_labels();
        if (!array_key_exists($provider, $labels)) {
            $provider = 'generic';
        }

        return [
            'id' => $provider,
            'label' => $labels[$provider],
        ];
    }

    private function ai_provider_labels(): array
    {
        return [
            'generic' => 'Generic AI assistant',
            'openai_chatgpt' => 'OpenAI / ChatGPT',
            'anthropic_claude' => 'Anthropic Claude',
            'google_gemini' => 'Google Gemini',
            'microsoft_copilot' => 'Microsoft Copilot',
            'custom_company_approved' => 'Custom / company-approved AI',
        ];
    }

    private function policy_notice(string $policy, string $custom_note, string $language): string
    {
        if ($policy === 'custom_policy' && $custom_note !== '') {
            return $custom_note;
        }

        $notices = $this->ai_policy_notices($language);
        return $notices[$policy] ?? $notices['not_documented'];
    }

    private function ai_policy_notices(string $language): array
    {
        if (strpos(strtolower($language), 'de') === 0) {
            return [
                'not_documented' => 'Es wurde keine kundenspezifische Einschränkung zur KI-Nutzung im Plugin dokumentiert.',
                'public_content_only' => 'Nutzen Sie diesen Prompt nur mit Inhalten, die bereits öffentlich oder zur Veröffentlichung freigegeben sind.',
                'no_personal_data' => 'Kopieren Sie keine personenbezogenen Daten in externe KI-Tools. Prüfen und entfernen Sie personenbezogene Daten vor der Nutzung dieses Prompts.',
                'no_confidential_content' => 'Kopieren Sie keine vertraulichen oder unveröffentlichten internen Informationen in externe KI-Tools.',
                'company_approved_tools_only' => 'Nutzen Sie diesen Prompt ausschließlich in vom Kunden/Unternehmen freigegebenen KI-Tools.',
                'custom_policy' => 'Für diesen Prompt ist eine kundenspezifische KI-Nutzungsrichtlinie vorgesehen. Bitte prüfen Sie die intern freigegebenen Hinweise, bevor Sie Inhalte in ein KI-Tool kopieren.',
            ];
        }

        return [
            'not_documented' => 'No customer-specific AI usage restriction has been documented in this plugin.',
            'public_content_only' => 'Use this prompt only with content that is already public or approved for publication.',
            'no_personal_data' => 'Do not copy personal data into external AI tools. Review and remove personal data before using this prompt.',
            'no_confidential_content' => 'Do not copy confidential or unpublished internal information into external AI tools.',
            'company_approved_tools_only' => 'Use this prompt only in AI tools approved by the customer/company.',
            'custom_policy' => 'A custom AI usage policy is configured for this prompt. Review the approved internal guidance before copying content into an AI tool.',
        ];
    }

    private function allowed_value(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function localized_brand_guidance(string $language): array
    {
        if (strpos(strtolower($language), 'en') === 0) {
            return [
                'tone' => [
                    'clear and understandable',
                    'editorially careful',
                    'helpful and specific',
                    'not overly promotional',
                    'aligned with the brand',
                ],
                'avoid_phrases' => [
                    'guaranteed perfect',
                    'sensational',
                    'unique for everyone',
                    'you absolutely have to see this',
                ],
            ];
        }

        return [
            'tone' => $this->config->get('brand_guidance.tone', $this->config->get('tone', [])),
            'avoid_phrases' => $this->config->get('brand_guidance.avoid_phrases', $this->config->get('avoid_phrases', [])),
        ];
    }

    private function prompt_texts(string $language): array
    {
        if (strpos(strtolower($language), 'en') === 0) {
            return [
                'role' => 'You are an editorial content assistant for %s.',
                'task' => 'Analyze the content and create structured suggestions for the active channels. Do not publish anything automatically.',
                'output_language' => 'Write all generated output, editorial feedback, warnings, and suggested social copy in English.',
                'no_fabrication' => 'Do not invent facts.',
                'content_incomplete' => 'If excerpt and content are empty or incomplete, do not invent concrete details. Create only general suggestions based on title, categories and checks, and clearly state that the article text is missing.',
                'advertising_detected' => 'Advertising disclosure was detected. Do not remove it; keep #Anzeige or an appropriate disclosure visible in social media and newsletter suggestions.',
                'placeholder_excerpt' => 'The current excerpt appears to be a placeholder. Suggest a new editorial excerpt.',
                'dates_without_year' => 'Dates without a year were detected. Mark this as an editorial improvement note.',
                'structured_fields' => 'Structured fields such as address, coordinates, Google Maps link, external link and image credit should be preferred over uncertain content parsing.',
                'coordinates_no_address' => 'Coordinates are available but no address was provided. Do not invent an address from coordinates.',
                'image_credit' => 'Image credit is available as an editorial fact, but do not automatically include it in Instagram captions unless explicitly requested.',
                'internal_notes' => 'Internal editorial notes or unfinished draft comments were detected. Do not reuse them in social media or newsletter copy. Point them out specifically in the editorial improvement notes.',
                'language' => 'en',
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
            'output_language' => 'Schreibe alle generierten Ausgaben, redaktionelles Feedback, Warnungen und Social-Copy-Vorschläge auf Deutsch.',
            'no_fabrication' => 'Keine Fakten erfinden.',
            'content_incomplete' => 'Wenn Auszug und Inhalt leer oder unvollständig sind, darfst du keine konkreten Details erfinden. Erstelle nur allgemeine Vorschläge auf Basis von Titel, Kategorien und Checks und weise deutlich darauf hin, dass der Beitragstext fehlt.',
            'advertising_detected' => 'Werbekennzeichnung wurde erkannt. Entferne sie nicht; halte #Anzeige oder eine geeignete Kennzeichnung in Social-Media- und Newsletter-Vorschlägen sichtbar.',
            'placeholder_excerpt' => 'Der aktuelle Auszug wirkt wie ein Platzhalter. Schlage einen neuen redaktionellen Auszug vor.',
            'dates_without_year' => 'Datumsangaben ohne Jahr wurden erkannt. Markiere dies als redaktionellen Verbesserungshinweis.',
            'structured_fields' => 'Strukturierte Felder wie Adresse, Koordinaten, Google Maps Link, externer Link und Bildquelle sind gegenüber unsicherem Content-Parsing zu bevorzugen.',
            'coordinates_no_address' => 'Koordinaten vorhanden, Adresse nicht angegeben. Erfinde keine Adresse aus Koordinaten.',
            'image_credit' => 'Bildquelle ist als redaktioneller Fakt vorhanden, aber nicht automatisch in Instagram Caption einbauen, außer ausdrücklich gewünscht.',
            'internal_notes' => 'Interne Notizen oder unfertige Stellen wurden erkannt. Diese dürfen nicht in Social-Media- oder Newsletter-Texte übernommen werden. Weise in den redaktionellen Verbesserungshinweisen konkret darauf hin.',
            'language' => 'de',
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

    private function localized_editorial_rules(array $texts, string $language): array
    {
        $configured = (array) $this->config->get('editorial_rules', []);
        if (strpos(strtolower($language), 'en') === 0) {
            return $texts['default_rules'];
        }
        return $configured ?: $texts['default_rules'];
    }

    private function limit_content(string $content): string
    {
        return trim(mb_substr($content, 0, 12000));
    }
}
