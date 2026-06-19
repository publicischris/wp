<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Admin
{
    private const NONCE_ACTION = 'kimapa_content_assistant';

    /** @var Config */ private $config;
    /** @var Meta */ private $meta;
    /** @var Analyzer */ private $analyzer;
    /** @var Prompt_Builder */ private $prompt_builder;
    /** @var Content_Extractor */ private $content_extractor;

    public function __construct(Config $config, Meta $meta, Analyzer $analyzer, Prompt_Builder $prompt_builder, Content_Extractor $content_extractor)
    {
        $this->config = $config;
        $this->meta = $meta;
        $this->analyzer = $analyzer;
        $this->prompt_builder = $prompt_builder;
        $this->content_extractor = $content_extractor;
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_kimapa_ca_analyze', [$this, 'ajax_analyze']);
        add_action('wp_ajax_kimapa_ca_save', [$this, 'ajax_save']);
    }

    public function add_meta_box(): void
    {
        add_meta_box('kimapa-content-assistant', __('KiMaPa Content Assistant', 'kimapa-content-assistant'), [$this, 'render_meta_box'], 'post', 'side', 'high');
    }

    public function enqueue_assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        wp_enqueue_style('kimapa-content-assistant-admin', KIMAPA_CA_URL . 'assets/admin.css', [], KIMAPA_CA_VERSION);
        wp_enqueue_script('kimapa-content-assistant-admin', KIMAPA_CA_URL . 'assets/admin.js', ['jquery'], KIMAPA_CA_VERSION, true);
        wp_localize_script('kimapa-content-assistant-admin', 'kimapaCA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => [
                'saving' => __('Speichern…', 'kimapa-content-assistant'),
                'analyzing' => __('Analysieren…', 'kimapa-content-assistant'),
                'saved' => __('Gespeichert.', 'kimapa-content-assistant'),
                'error' => __('Es ist ein Fehler aufgetreten.', 'kimapa-content-assistant'),
            ],
        ]);
    }

    public function render_meta_box(\WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            esc_html_e('Keine Berechtigung.', 'kimapa-content-assistant');
            return;
        }
        $meta = $this->meta->get_all((int) $post->ID);
        $checks = json_decode((string) $meta['_kimapa_content_checks'], true);
        if (!is_array($checks)) {
            $checks = [];
        }
        ?>
        <div class="kimapa-ca" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, 'kimapa_ca_nonce'); ?>
            <button type="button" class="button button-primary kimapa-ca-analyze"><?php esc_html_e('Beitrag analysieren', 'kimapa-content-assistant'); ?></button>
            <div class="kimapa-ca-status" aria-live="polite"></div>
            <h4><?php esc_html_e('Gesamtbewertung/Score', 'kimapa-content-assistant'); ?></h4>
            <div class="kimapa-ca-score"><strong><?php echo esc_html($meta['_kimapa_content_score'] !== '' ? (string) $meta['_kimapa_content_score'] : '–'); ?></strong>/100</div>
            <h4><?php esc_html_e('Checkliste', 'kimapa-content-assistant'); ?></h4>
            <ul class="kimapa-ca-checks"><?php $this->render_checks($checks); ?></ul>
            <?php $this->textarea('_kimapa_generated_prompt', __('Kopierbarer KI-Prompt', 'kimapa-content-assistant'), $meta, 8, true); ?>
            <?php $this->textarea('_kimapa_instagram_caption', __('Instagram Caption Vorschlag', 'kimapa-content-assistant'), $meta); ?>
            <?php $this->textarea('_kimapa_instagram_hashtags', __('Hashtags', 'kimapa-content-assistant'), $meta); ?>
            <?php $this->textarea('_kimapa_instagram_cta', __('Call to Action', 'kimapa-content-assistant'), $meta); ?>
            <?php $this->textarea('_kimapa_instagram_story_idea', __('Story-Idee', 'kimapa-content-assistant'), $meta); ?>
            <?php $this->textarea('_kimapa_instagram_carousel_idea', __('Carousel-Idee', 'kimapa-content-assistant'), $meta); ?>
            <?php $this->textarea('_kimapa_newsletter_teaser', __('Newsletter-Teaser', 'kimapa-content-assistant'), $meta); ?>
            <p><label for="kimapa_ca_instagram_url"><?php esc_html_e('Instagram-Link', 'kimapa-content-assistant'); ?></label><input class="widefat" id="kimapa_ca_instagram_url" name="_kimapa_instagram_url" type="url" value="<?php echo esc_attr((string) $meta['_kimapa_instagram_url']); ?>"></p>
            <div class="kimapa-ca-grid">
                <?php foreach (['_kimapa_instagram_likes' => 'Likes', '_kimapa_instagram_comments' => 'Kommentare', '_kimapa_instagram_shares' => 'Shares', '_kimapa_instagram_saves' => 'Saves', '_kimapa_instagram_reach' => 'Reichweite', '_kimapa_instagram_impressions' => 'Impressionen'] as $key => $label) : ?>
                    <p><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" type="number" min="0" value="<?php echo esc_attr((string) $meta[$key]); ?>"></label></p>
                <?php endforeach; ?>
            </div>
            <?php $this->render_extraction_debug((int) $post->ID); ?>
            <button type="button" class="button kimapa-ca-save"><?php esc_html_e('Speichern', 'kimapa-content-assistant'); ?></button>
        </div>
        <?php
    }

    public function ajax_analyze(): void
    {
        $post_id = $this->validated_post_id();
        $analysis = $this->analyzer->analyze($post_id);
        $prompt = $this->prompt_builder->build($post_id, $analysis);
        $this->meta->save_analysis($post_id, $analysis, $prompt);
        wp_send_json_success(['analysis' => $analysis, 'prompt' => $prompt]);
    }

    public function ajax_save(): void
    {
        $post_id = $this->validated_post_id();
        $this->meta->save_manual_fields($post_id, $_POST);
        wp_send_json_success(['message' => __('Gespeichert.', 'kimapa-content-assistant')]);
    }

    private function validated_post_id(): int
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'kimapa-content-assistant')], 403);
        }
        return $post_id;
    }

    private function render_extraction_debug(int $post_id): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $debug = $this->content_extractor->extract($post_id);
        ?>
        <details class="kimapa-ca-debug">
            <summary><?php esc_html_e('Content-Extraktion Debug', 'kimapa-content-assistant'); ?></summary>
            <ul>
                <li><?php printf(esc_html__('Rohinhalt vorhanden: %s', 'kimapa-content-assistant'), esc_html($debug['raw_content_present'] ? __('ja', 'kimapa-content-assistant') : __('nein', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Bereinigter Inhalt Länge: %d Zeichen', 'kimapa-content-assistant'), absint($debug['content_length'])); ?></li>
                <li><?php printf(esc_html__('Wortanzahl: %d', 'kimapa-content-assistant'), absint($debug['content_word_count'])); ?></li>
                <li><?php printf(esc_html__('Excerpt-Quelle: %s', 'kimapa-content-assistant'), esc_html((string) $debug['excerpt_source'])); ?></li>
                <li><?php printf(esc_html__('Content Extraction Status: %s', 'kimapa-content-assistant'), esc_html((string) $debug['content_extraction_status'])); ?></li>
            </ul>
        </details>
        <?php
    }

    private function textarea(string $key, string $label, array $meta, int $rows = 3, bool $readonly = false): void
    {
        printf('<p><label for="kimapa_ca_%1$s">%2$s</label><textarea class="widefat" id="kimapa_ca_%1$s" name="%1$s" rows="%3$d"%4$s>%5$s</textarea></p>', esc_attr($key), esc_html($label), absint($rows), $readonly ? ' readonly' : '', esc_textarea((string) ($meta[$key] ?? '')));
    }

    private function render_checks(array $checks): void
    {
        if (!$checks) {
            echo '<li>' . esc_html__('Noch keine Analyse durchgeführt.', 'kimapa-content-assistant') . '</li>';
            return;
        }
        foreach ($checks as $check) {
            $class = !empty($check['passed']) ? 'is-passed' : 'is-open';
            printf('<li class="%1$s"><strong>%2$s</strong><br><span>%3$s</span></li>', esc_attr($class), esc_html((string) ($check['label'] ?? '')), esc_html((string) ($check['message'] ?? '')));
        }
    }
}
