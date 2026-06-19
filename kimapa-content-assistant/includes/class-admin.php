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
                'copied' => __('Kopiert', 'kimapa-content-assistant'),
                'copy' => __('Kopieren', 'kimapa-content-assistant'),
                'invalidJson' => __('Ungültiges JSON. Es wurde nichts überschrieben.', 'kimapa-content-assistant'),
                'overwriteWarning' => __('Bestehende Inhalte werden überschrieben. Fortfahren?', 'kimapa-content-assistant'),
                'applied' => __('KI-Ergebnis übernommen. Bitte speichern.', 'kimapa-content-assistant'),
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
            <div class="kimapa-ca-status" aria-live="polite"></div>

            <details class="kimapa-ca-section" open>
                <summary><?php esc_html_e('Analyse', 'kimapa-content-assistant'); ?></summary>
                <button type="button" class="button button-primary button-small kimapa-ca-analyze"><?php esc_html_e('Beitrag analysieren', 'kimapa-content-assistant'); ?></button>
                <h4><?php esc_html_e('Gesamtbewertung/Score', 'kimapa-content-assistant'); ?></h4>
                <div class="kimapa-ca-score"><strong><?php echo esc_html($meta['_kimapa_content_score'] !== '' ? (string) $meta['_kimapa_content_score'] : '–'); ?></strong>/100</div>
                <h4><?php esc_html_e('Checkliste', 'kimapa-content-assistant'); ?></h4>
                <ul class="kimapa-ca-checks"><?php $this->render_checks($checks); ?></ul>
            </details>

            <details class="kimapa-ca-section" open>
                <summary><?php esc_html_e('KI-Prompt', 'kimapa-content-assistant'); ?></summary>
                <?php $this->textarea('_kimapa_generated_prompt', __('JSON-KI-Prompt', 'kimapa-content-assistant'), $meta, 9, true, true); ?>
            </details>

            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('KI-Ergebnis einfügen', 'kimapa-content-assistant'); ?></summary>
                <p class="description"><?php esc_html_e('JSON aus dem KI-Tool hier einfügen. Beim Übernehmen werden bestehende Inhalte nach Bestätigung überschrieben.', 'kimapa-content-assistant'); ?></p>
                <?php $this->textarea('_kimapa_ai_result_raw', __('KI-Ergebnis JSON einfügen', 'kimapa-content-assistant'), $meta, 8, false, false); ?>
                <button type="button" class="button button-small kimapa-ca-apply-ai-result"><?php esc_html_e('KI-Ergebnis übernehmen', 'kimapa-content-assistant'); ?></button>
            </details>

            <details class="kimapa-ca-section" open>
                <summary><?php esc_html_e('Social Copy', 'kimapa-content-assistant'); ?></summary>
                <?php $this->textarea('_kimapa_instagram_caption', __('Instagram Caption Vorschlag', 'kimapa-content-assistant'), $meta, 4, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_caption_variant_1', __('Caption Variante 1 emotional', 'kimapa-content-assistant'), $meta, 3, false, false); ?>
                <?php $this->textarea('_kimapa_instagram_caption_variant_2', __('Caption Variante 2 praktisch', 'kimapa-content-assistant'), $meta, 3, false, false); ?>
                <?php $this->textarea('_kimapa_instagram_caption_variant_3', __('Caption Variante 3 kurz', 'kimapa-content-assistant'), $meta, 3, false, false); ?>
                <?php $this->textarea('_kimapa_hook', __('Hook', 'kimapa-content-assistant'), $meta, 2, false, false); ?>
                <?php $this->textarea('_kimapa_instagram_hashtags', __('Hashtags', 'kimapa-content-assistant'), $meta, 2, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_cta', __('Call to Action', 'kimapa-content-assistant'), $meta, 2, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_story_idea', __('Story-Idee', 'kimapa-content-assistant'), $meta, 3, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_carousel_idea', __('Carousel-Idee', 'kimapa-content-assistant'), $meta, 3, false, true); ?>
                <?php $this->textarea('_kimapa_newsletter_teaser', __('Newsletter-Teaser', 'kimapa-content-assistant'), $meta, 3, false, true); ?>
                <?php $this->textarea('_kimapa_editorial_improvement_notes', __('Redaktionelle Verbesserungshinweise', 'kimapa-content-assistant'), $meta, 4, false, false); ?>
            </details>

            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('Instagram Performance', 'kimapa-content-assistant'); ?></summary>
                <p class="description"><?php esc_html_e('Ohne Instagram-API können diese Werte manuell gepflegt werden. Der Link dient als Referenz zur späteren Auswertung.', 'kimapa-content-assistant'); ?></p>
                <p><label for="kimapa_ca_instagram_url"><?php esc_html_e('Instagram-Link', 'kimapa-content-assistant'); ?></label><input class="widefat kimapa-ca-copy-source" id="kimapa_ca_instagram_url" name="_kimapa_instagram_url" type="url" value="<?php echo esc_attr((string) $meta['_kimapa_instagram_url']); ?>" inputmode="url"><button type="button" class="button button-small kimapa-ca-copy" data-copy-target="#kimapa_ca_instagram_url"><?php esc_html_e('Instagram-Link kopieren', 'kimapa-content-assistant'); ?></button></p>
                <div class="kimapa-ca-grid">
                    <?php foreach (['_kimapa_instagram_likes' => 'Likes', '_kimapa_instagram_comments' => 'Kommentare', '_kimapa_instagram_shares' => 'Shares', '_kimapa_instagram_saves' => 'Saves', '_kimapa_instagram_reach' => 'Reichweite', '_kimapa_instagram_impressions' => 'Impressionen'] as $key => $label) : ?>
                        <p><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" type="number" min="0" step="1" value="<?php echo esc_attr((string) $meta[$key]); ?>" inputmode="numeric"></label></p>
                    <?php endforeach; ?>
                </div>
            </details>

            <?php $this->render_extraction_debug((int) $post->ID, $meta); ?>
            <button type="button" class="button button-primary kimapa-ca-save"><?php esc_html_e('Speichern', 'kimapa-content-assistant'); ?></button>
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

    private function render_extraction_debug(int $post_id, array $meta): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $debug = $this->content_extractor->extract($post_id);
        $relative_terms = $this->decode_meta_array($meta['_kimapa_detected_relative_time_terms'] ?? '');
        $outdated_terms = $this->decode_meta_array($meta['_kimapa_detected_outdated_terms'] ?? '');
        $dates_without_year = $this->decode_meta_array($meta['_kimapa_dates_without_year'] ?? '');
        ?>
        <details class="kimapa-ca-section kimapa-ca-debug">
            <summary><?php esc_html_e('Debug', 'kimapa-content-assistant'); ?></summary>
            <ul>
                <li><?php printf(esc_html__('Rohinhalt vorhanden: %s', 'kimapa-content-assistant'), esc_html($debug['raw_content_present'] ? __('ja', 'kimapa-content-assistant') : __('nein', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Bereinigter Inhalt Wortanzahl: %d', 'kimapa-content-assistant'), absint($debug['content_word_count'])); ?></li>
                <li><?php printf(esc_html__('Content Extraction Status: %s', 'kimapa-content-assistant'), esc_html((string) $debug['content_extraction_status'])); ?></li>
                <li><?php printf(esc_html__('Excerpt-Quelle: %s', 'kimapa-content-assistant'), esc_html($this->debug_excerpt_source($debug, $meta))); ?></li>
                <li><?php printf(esc_html__('Advertising Disclosure erkannt: %s', 'kimapa-content-assistant'), esc_html(($meta['_kimapa_advertising_disclosure_detected'] ?? '') === '1' ? __('ja', 'kimapa-content-assistant') : __('nein', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Relative Zeitbegriffe gefunden: %s', 'kimapa-content-assistant'), esc_html($relative_terms ? implode(', ', $relative_terms) : '–')); ?></li>
                <li><?php printf(esc_html__('Potenziell veraltete Begriffe gefunden: %s', 'kimapa-content-assistant'), esc_html($outdated_terms ? implode(', ', $outdated_terms) : '–')); ?></li>
                <li><?php printf(esc_html__('Datumsangaben ohne Jahr gefunden: %s', 'kimapa-content-assistant'), esc_html($dates_without_year ? implode(', ', $dates_without_year) : '–')); ?></li>
            </ul>
        </details>
        <?php
    }

    private function textarea(string $key, string $label, array $meta, int $rows = 3, bool $readonly = false, bool $copy_button = false): void
    {
        $id = 'kimapa_ca_' . trim($key, '_');
        printf('<p class="kimapa-ca-field"><label for="%1$s">%2$s</label><textarea class="widefat kimapa-ca-copy-source" id="%1$s" name="%3$s" rows="%4$d"%5$s>%6$s</textarea>', esc_attr($id), esc_html($label), esc_attr($key), absint($rows), $readonly ? ' readonly' : '', esc_textarea((string) ($meta[$key] ?? '')));
        if ($copy_button) {
            printf('<button type="button" class="button button-small kimapa-ca-copy" data-copy-target="#%1$s">%2$s</button>', esc_attr($id), esc_html(sprintf(__('%s kopieren', 'kimapa-content-assistant'), $label)));
        }
        echo '</p>';
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

    private function decode_meta_array($value): array
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    private function debug_excerpt_source(array $debug, array $meta): string
    {
        if (($meta['_kimapa_placeholder_excerpt_detected'] ?? '') === '1') {
            return 'Platzhalter';
        }
        return (string) $debug['excerpt_source'];
    }
}
