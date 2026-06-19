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

        $payload = [
            'role' => 'Du bist ein redaktioneller Content Assistant für das Familienportal KiMaPa.',
            'task' => 'Analysiere den Beitrag und erstelle strukturierte Social-Media- und Newsletter-Vorschläge. Veröffentliche nichts automatisch.',
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
                'tone' => $this->config->get('tone', []),
                'avoid_phrases' => $this->config->get('avoid_phrases', []),
            ],
            'editorial_rules' => [
                'Keine Fakten erfinden.',
                'Nur Informationen verwenden, die im Beitrag, in Kategorien, Tags, Checks oder extracted_facts vorhanden sind.',
                'Wenn Inhalte veraltet wirken, in den redaktionellen Hinweisen deutlich markieren.',
                'Wenn advertising_disclosure_detected true ist, muss #Anzeige oder eine geeignete Werbekennzeichnung in Instagram Caption und Newsletter-Hinweisen sichtbar bleiben.',
                'Wenn der Excerpt als Platzhalter erkannt wurde, einen neuen redaktionellen Excerpt vorschlagen.',
                'Wenn Datumsangaben ohne Jahr gefunden wurden, dies als Verbesserungshinweis aufnehmen.',
            ],
            'required_output' => [
                'instagram_caption_variant_1_emotional',
                'instagram_caption_variant_2_practical',
                'instagram_caption_variant_3_short',
                'hook',
                'cta',
                'hashtags',
                'story_idea',
                'carousel_idea',
                'newsletter_teaser',
                'editorial_improvement_notes',
            ],
            'conditional_instructions' => array_values(array_filter([
                $content_is_incomplete ? 'Wenn excerpt und content leer oder unvollständig sind, darfst du keine konkreten Details erfinden. Erstelle nur allgemeine Vorschläge auf Basis von Titel, Kategorie und Checks und weise deutlich darauf hin, dass der Beitragstext fehlt.' : '',
                $advertising_detected ? 'Werbekennzeichnung wurde erkannt. Entferne sie nicht; halte sie in Instagram Caption und Newsletter-Hinweisen sichtbar.' : '',
                $placeholder_excerpt ? 'Der aktuelle Auszug wirkt wie ein Platzhalter. Schlage einen neuen redaktionellen Excerpt vor.' : '',
                $dates_without_year ? 'Datumsangaben ohne Jahr wurden erkannt. Markiere dies als redaktionellen Verbesserungshinweis.' : '',
            ])),
            'format' => $this->config->get('output_formats', []),
        ];

        return wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function limit_content(string $content): string
    {
        return trim(mb_substr($content, 0, 12000));
    }
}
