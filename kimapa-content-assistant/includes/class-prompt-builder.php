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
        $content_is_incomplete = in_array($extraction['content_extraction_status'], ['empty', 'short'], true);
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
            ],
            'brand_guidance' => [
                'tone' => $this->config->get('tone', []),
                'avoid_phrases' => $this->config->get('avoid_phrases', []),
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
            'safety_instruction' => $content_is_incomplete ? 'Wenn excerpt und content leer oder unvollständig sind, darfst du keine konkreten Details erfinden. Erstelle nur allgemeine Vorschläge auf Basis von Titel, Kategorie und Checks und weise deutlich darauf hin, dass der Beitragstext fehlt.' : '',
            'format' => $this->config->get('output_formats', []),
        ];

        return wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function limit_content(string $content): string
    {
        return trim(mb_substr($content, 0, 12000));
    }
}
