<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Prompt_Builder
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function build(int $post_id, array $analysis): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $categories = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $payload = [
            'role' => 'Du bist ein redaktioneller Content Assistant für das Familienportal KiMaPa.',
            'task' => 'Analysiere den Beitrag und erstelle strukturierte Social-Media- und Newsletter-Vorschläge. Veröffentliche nichts automatisch.',
            'input' => [
                'title' => get_the_title($post),
                'permalink' => get_permalink($post),
                'excerpt' => wp_strip_all_tags(get_the_excerpt($post)),
                'content' => $this->clean_content($post->post_content),
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
            'format' => $this->config->get('output_formats', []),
        ];

        return wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function clean_content(string $content): string
    {
        $content = strip_shortcodes($content);
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/u', ' ', (string) $content);
        return trim(mb_substr((string) $content, 0, 12000));
    }
}
