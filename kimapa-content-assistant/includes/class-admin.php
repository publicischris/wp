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
    /** @var Publications */ private $publications;

    public function __construct(Config $config, Meta $meta, Analyzer $analyzer, Prompt_Builder $prompt_builder, Content_Extractor $content_extractor, Publications $publications)
    {
        $this->config = $config;
        $this->meta = $meta;
        $this->analyzer = $analyzer;
        $this->prompt_builder = $prompt_builder;
        $this->content_extractor = $content_extractor;
        $this->publications = $publications;
    }

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_post_kimapa_ca_save_settings', [$this, 'save_settings']);
        add_action('admin_post_kimapa_ca_reset_settings', [$this, 'reset_settings']);
        add_action('admin_post_kimapa_ca_load_preset', [$this, 'load_preset']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_kimapa_ca_analyze', [$this, 'ajax_analyze']);
        add_action('wp_ajax_kimapa_ca_save', [$this, 'ajax_save']);
        add_action('wp_ajax_kimapa_ca_snapshot', [$this, 'ajax_snapshot']);
    }

    public function add_meta_box(): void
    {
        foreach ((array) $this->config->get('general.post_types', ['post']) as $post_type) {
            add_meta_box('kimapa-content-assistant', __('Content Assistant', 'kimapa-content-assistant'), [$this, 'render_meta_box'], $post_type, 'side', 'high');
        }
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
                'saving' => __('Saving…', 'kimapa-content-assistant'),
                'analyzing' => __('Analyzing…', 'kimapa-content-assistant'),
                'saved' => __('Saved.', 'kimapa-content-assistant'),
                'error' => __('An error occurred.', 'kimapa-content-assistant'),
                'copied' => __('Copied', 'kimapa-content-assistant'),
                'copy' => __('Copy', 'kimapa-content-assistant'),
                'invalidJson' => __('Invalid JSON. Nothing was overwritten.', 'kimapa-content-assistant'),
                'overwriteWarning' => __('Existing content will be overwritten. Continue?', 'kimapa-content-assistant'),
                'applied' => __('AI result applied. Please save.', 'kimapa-content-assistant'),
                'noChecks' => __('No checks available.', 'kimapa-content-assistant'),
                'showChecklist' => __('Show checklist', 'kimapa-content-assistant'),
                'hideChecklist' => __('Hide checklist', 'kimapa-content-assistant'),
                'snapshotSaved' => __('Snapshot saved.', 'kimapa-content-assistant'),
                'saveSnapshot' => __('Save publication-history snapshot', 'kimapa-content-assistant'),
                'analysisComplete' => __('Analysis completed.', 'kimapa-content-assistant'),
                'analyze' => __('Analyze content', 'kimapa-content-assistant'),
                'save' => __('Save', 'kimapa-content-assistant'),
                'nothingToCopy' => __('Nothing to copy.', 'kimapa-content-assistant'),
            ],
        ]);
    }


    public function register_settings_page(): void
    {
        add_menu_page(__('Content Assistant', 'kimapa-content-assistant'), __('Content Assistant', 'kimapa-content-assistant'), 'manage_options', 'kimapa-content-assistant', [$this, 'render_settings_page'], 'dashicons-edit-page');
        add_submenu_page('kimapa-content-assistant', __('Settings', 'kimapa-content-assistant'), __('Settings', 'kimapa-content-assistant'), 'manage_options', 'kimapa-content-assistant', [$this, 'render_settings_page']);
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kimapa-content-assistant'));
        }
        $config = $this->config->all();
        ?>
        <div class="wrap kimapa-ca-settings">
            <h1><?php esc_html_e('Content Assistant Settings', 'kimapa-content-assistant'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('kimapa_ca_save_settings'); ?>
                <input type="hidden" name="action" value="kimapa_ca_save_settings">
                <h2><?php esc_html_e('General', 'kimapa-content-assistant'); ?></h2>
                <?php $this->settings_text('general[plugin_name]', __('Plugin/project name', 'kimapa-content-assistant'), $config['general']['plugin_name'] ?? ''); ?>
                <?php $this->settings_text('general[brand_name]', __('Portal/brand name', 'kimapa-content-assistant'), $config['general']['brand_name'] ?? ''); ?>
                <?php $this->settings_textarea('general[portal_description]', __('Portal short description', 'kimapa-content-assistant'), $config['general']['portal_description'] ?? '', 3); ?>
                <?php $this->settings_select('general[language]', __('Prompt/output language', 'kimapa-content-assistant'), $config['general']['language'] ?? 'auto', $this->language_options()); ?>
                <p class="description"><?php esc_html_e('This setting controls the language of the AI prompt and generated output. The WordPress admin UI language follows the WordPress user/site language.', 'kimapa-content-assistant'); ?></p>
                <p class="description"><?php esc_html_e('Auto tries to detect the post or site language. If no language is detected, the plugin setting or site locale is used.', 'kimapa-content-assistant'); ?></p>
                <?php $this->settings_textarea('general[post_types]', __('Default post types (one per line)', 'kimapa-content-assistant'), implode("\n", (array) ($config['general']['post_types'] ?? ['post'])), 3); ?>
                <?php $this->settings_select('general[content_profile]', __('Content profile', 'kimapa-content-assistant'), $config['general']['content_profile'] ?? 'generic_editorial', $this->profile_options($config)); ?>

                <h2><?php esc_html_e('Enable channels', 'kimapa-content-assistant'); ?></h2>
                <?php foreach (['instagram' => 'Enable Instagram', 'newsletter' => 'Enable newsletter', 'editorial_review' => 'Enable editorial review', 'debug' => 'Enable debug panel'] as $key => $label) : ?>
                    <label><input type="checkbox" name="channels[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($config['channels'][$key])); ?>> <?php echo esc_html__($label, 'kimapa-content-assistant'); ?></label><br>
                <?php endforeach; ?>


                <h2><?php esc_html_e('Structured fields / custom field mapping', 'kimapa-content-assistant'); ?></h2>
                <p class="description"><?php esc_html_e('If your website already uses structured custom fields for addresses, coordinates or external links, enter the technical meta keys here. Content Assistant will prefer these values over automatic text detection.', 'kimapa-content-assistant'); ?></p>
                <p class="description"><?php esc_html_e('If no meta keys are entered, automatic detection from the post content remains active.', 'kimapa-content-assistant'); ?></p>
                <label><input type="checkbox" name="structured_fields[enabled]" value="1" <?php checked(!empty($config['structured_fields']['enabled'])); ?>> <?php esc_html_e('Use structured location fields', 'kimapa-content-assistant'); ?></label><br>
                <label><input type="checkbox" name="structured_fields[fallback_to_content_parsing]" value="1" <?php checked(!isset($config['structured_fields']['fallback_to_content_parsing']) || !empty($config['structured_fields']['fallback_to_content_parsing'])); ?>> <?php esc_html_e('Extract from post content when structured fields are empty', 'kimapa-content-assistant'); ?></label>
                <?php foreach (['latitude' => 'Latitude Meta Key', 'longitude' => 'Longitude Meta Key', 'google_maps_link' => 'Google Maps Link Meta Key', 'street' => 'Street meta key', 'zip' => 'ZIP/postal code meta key', 'city' => 'City meta key', 'external_link' => 'External link meta key', 'image_credit' => 'Image credit meta key'] as $field => $label) : ?>
                    <?php $this->settings_text('structured_fields[meta_keys][' . $field . ']', __($label, 'kimapa-content-assistant'), $config['structured_fields']['meta_keys'][$field] ?? ''); ?>
                    <p class="description"><?php esc_html_e('Enter the technical meta key, not the visible label.', 'kimapa-content-assistant'); ?></p>
                <?php endforeach; ?>

                <h2><?php esc_html_e('Brand Guidance', 'kimapa-content-assistant'); ?></h2>
                <?php $this->settings_textarea('brand_guidance[tone]', __('Tone (one entry per line)', 'kimapa-content-assistant'), implode("\n", (array) ($config['brand_guidance']['tone'] ?? $config['tone'] ?? [])), 6); ?>
                <?php $this->settings_textarea('brand_guidance[avoid_phrases]', __('Avoid phrases', 'kimapa-content-assistant'), implode("\n", (array) ($config['brand_guidance']['avoid_phrases'] ?? $config['avoid_phrases'] ?? [])), 5); ?>

                <h2><?php esc_html_e('Editorial Rules', 'kimapa-content-assistant'); ?></h2>
                <?php $this->settings_textarea('editorial_rules', __('Rules (one per line)', 'kimapa-content-assistant'), implode("\n", (array) ($config['editorial_rules'] ?? [])), 7); ?>

                <h2><?php esc_html_e('Required Output', 'kimapa-content-assistant'); ?></h2>
                <?php $this->settings_textarea('required_output', __('Required output fields', 'kimapa-content-assistant'), implode("\n", (array) ($config['required_output'] ?? [])), 8); ?>

                <h2><?php esc_html_e('Format settings', 'kimapa-content-assistant'); ?></h2>
                <?php $this->settings_text('format_settings[preferred]', __('Preferred output format', 'kimapa-content-assistant'), $config['format_settings']['preferred'] ?? 'JSON'); ?>
                <?php $this->settings_text('format_settings[instagram_caption_variants]', __('Instagram caption variants', 'kimapa-content-assistant'), $config['format_settings']['instagram_caption_variants'] ?? 3, 'number'); ?>
                <?php $this->settings_text('format_settings[hashtag_count]', __('Hashtag count', 'kimapa-content-assistant'), $config['format_settings']['hashtag_count'] ?? '8-15'); ?>
                <label><input type="checkbox" name="format_settings[include_hook]" value="1" <?php checked(!empty($config['format_settings']['include_hook'])); ?>> <?php esc_html_e('Include hook', 'kimapa-content-assistant'); ?></label><br>
                <label><input type="checkbox" name="format_settings[include_cta]" value="1" <?php checked(!empty($config['format_settings']['include_cta'])); ?>> <?php esc_html_e('Include CTA', 'kimapa-content-assistant'); ?></label>
                <?php $this->settings_text('format_settings[newsletter_max_characters]', __('Newsletter max characters', 'kimapa-content-assistant'), $config['format_settings']['newsletter_max_characters'] ?? 450, 'number'); ?>
                <?php $this->settings_text('format_settings[newsletter_style]', __('Newsletter style', 'kimapa-content-assistant'), $config['format_settings']['newsletter_style'] ?? ''); ?>

                <h2><?php esc_html_e('Raw JSON Preview', 'kimapa-content-assistant'); ?></h2>
                <textarea readonly class="large-text code" rows="14"><?php echo esc_textarea(wp_json_encode($this->config->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></textarea>
                <?php submit_button(__('Save settings', 'kimapa-content-assistant')); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Existing configuration will be overwritten. Continue?');">
                <?php wp_nonce_field('kimapa_ca_load_preset'); ?>
                <input type="hidden" name="action" value="kimapa_ca_load_preset">
                <?php $this->settings_select('preset', __('Load preset', 'kimapa-content-assistant'), $config['general']['content_profile'] ?? 'generic_editorial', $this->profile_options($config)); ?>
                <?php submit_button(__('Load preset', 'kimapa-content-assistant'), 'secondary'); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Really reset configuration to defaults?');">
                <?php wp_nonce_field('kimapa_ca_reset_settings'); ?>
                <input type="hidden" name="action" value="kimapa_ca_reset_settings">
                <?php submit_button(__('Reset configuration', 'kimapa-content-assistant'), 'delete'); ?>
            </form>
        </div>
        <?php
    }

    public function save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kimapa-content-assistant'));
        }
        check_admin_referer('kimapa_ca_save_settings');
        $this->config->save(wp_unslash($_POST));
        wp_safe_redirect(admin_url('admin.php?page=kimapa-content-assistant&updated=1'));
        exit;
    }

    public function reset_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kimapa-content-assistant'));
        }
        check_admin_referer('kimapa_ca_reset_settings');
        $this->config->reset();
        wp_safe_redirect(admin_url('admin.php?page=kimapa-content-assistant&reset=1'));
        exit;
    }

    public function load_preset(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'kimapa-content-assistant'));
        }
        check_admin_referer('kimapa_ca_load_preset');
        $preset = isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : 'generic_editorial';
        $this->config->apply_preset($preset);
        wp_safe_redirect(admin_url('admin.php?page=kimapa-content-assistant&preset=1'));
        exit;
    }

    public function render_meta_box(\WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            esc_html_e('Permission denied.', 'kimapa-content-assistant');
            return;
        }
        $meta = $this->meta->get_all((int) $post->ID);
        $checks = json_decode((string) $meta['_kimapa_content_checks'], true);
        if (!is_array($checks)) {
            $checks = [];
        }
        $checks = $this->normalize_unicode_data($checks);
        $instagram_enabled = $this->config->is_channel_enabled('instagram');
        $newsletter_enabled = $this->config->is_channel_enabled('newsletter');
        $debug_enabled = $this->config->is_channel_enabled('debug');
        $warnings = count(array_filter($checks, static function ($check) { return empty($check['passed']) && ($check['severity'] ?? '') === 'warning'; }));
        $notices = count(array_filter($checks, static function ($check) { return empty($check['passed']) && ($check['severity'] ?? '') === 'notice'; }));
        $internal_notes_found = $this->check_has_findings($checks, 'internal_editorial_notes');
        $score_label = $this->score_label_from_score((int) $meta['_kimapa_content_score']);
        ?>
        <div class="kimapa-ca" data-post-id="<?php echo esc_attr((string) $post->ID); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, 'kimapa_ca_nonce'); ?>
            <div class="kimapa-ca-status" aria-live="polite"></div>

            <details class="kimapa-ca-section" open>
                <summary><?php esc_html_e('Score', 'kimapa-content-assistant'); ?></summary>
                <p><label for="content_assistant_prompt_language"><?php esc_html_e('Prompt language', 'kimapa-content-assistant'); ?></label><select class="widefat" id="content_assistant_prompt_language" name="_content_assistant_prompt_language"><?php foreach ($this->language_options() as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected(($meta['_content_assistant_prompt_language'] ?? 'auto') ?: 'auto', $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
                <button type="button" class="button button-primary button-small kimapa-ca-analyze"><?php esc_html_e('Analyze content', 'kimapa-content-assistant'); ?></button>
                <div class="kimapa-ca-score"><strong><?php printf(esc_html__('Score: %s/100', 'kimapa-content-assistant'), esc_html($meta['_kimapa_content_score'] !== '' ? (string) $meta['_kimapa_content_score'] : '–')); ?></strong><br><span><?php echo esc_html($score_label); ?></span></div>
                <p class="kimapa-ca-summary"><?php printf(esc_html__('%1$d warnings, %2$d notices', 'kimapa-content-assistant'), absint($warnings), absint($notices)); ?></p>
                <?php if ($internal_notes_found) : ?><p class="kimapa-ca-alert"><?php esc_html_e('Review internal notes', 'kimapa-content-assistant'); ?></p><?php endif; ?>
            </details>
            <details class="kimapa-ca-section kimapa-ca-checklist">
                <summary><?php esc_html_e('Show checklist', 'kimapa-content-assistant'); ?></summary>
                <ul class="kimapa-ca-checks"><?php $this->render_checks($checks); ?></ul>
            </details>

            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('AI prompt', 'kimapa-content-assistant'); ?></summary>
                <?php $this->textarea('_kimapa_generated_prompt', __('JSON AI prompt', 'kimapa-content-assistant'), $meta, 9, true, true); ?>
            </details>

            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('Paste AI result', 'kimapa-content-assistant'); ?></summary>
                <p class="description"><?php esc_html_e('Paste JSON from the AI tool here. Applying it overwrites existing content after confirmation.', 'kimapa-content-assistant'); ?></p>
                <?php $this->textarea('_kimapa_ai_result_raw', __('Paste AI result JSON', 'kimapa-content-assistant'), $meta, 8, false, false); ?>
                <button type="button" class="button button-small kimapa-ca-apply-ai-result"><?php esc_html_e('Apply AI result', 'kimapa-content-assistant'); ?></button>
            </details>

            <?php if ($instagram_enabled) : ?>
            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('Social copy', 'kimapa-content-assistant'); ?></summary>
                <?php $this->textarea('_kimapa_instagram_caption', __('Instagram caption suggestion', 'kimapa-content-assistant'), $meta, 4, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_caption_variant_1', __('Caption variant 1 emotional', 'kimapa-content-assistant'), $meta, 3, false, false); ?>
                <?php $this->textarea('_kimapa_instagram_caption_variant_2', __('Caption variant 2 practical', 'kimapa-content-assistant'), $meta, 3, false, false); ?>
                <?php $this->textarea('_kimapa_instagram_caption_variant_3', __('Caption variant 3 short', 'kimapa-content-assistant'), $meta, 3, false, false); ?>
                <?php $this->textarea('_kimapa_hook', __('Hook', 'kimapa-content-assistant'), $meta, 2, false, false); ?>
                <?php $this->textarea('_kimapa_instagram_hashtags', __('Hashtags', 'kimapa-content-assistant'), $meta, 2, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_cta', __('Call to action', 'kimapa-content-assistant'), $meta, 2, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_story_idea', __('Story idea', 'kimapa-content-assistant'), $meta, 3, false, true); ?>
                <?php $this->textarea('_kimapa_instagram_carousel_idea', __('Carousel idea', 'kimapa-content-assistant'), $meta, 3, false, true); ?>
                <?php $this->textarea('_kimapa_editorial_improvement_notes', __('Editorial improvement notes', 'kimapa-content-assistant'), $meta, 4, false, false); ?>
            </details>
            <?php endif; ?>

            <?php if ($newsletter_enabled) : ?>
            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('Newsletter', 'kimapa-content-assistant'); ?></summary>
                <?php $this->textarea('_kimapa_newsletter_teaser', __('Newsletter teaser', 'kimapa-content-assistant'), $meta, 3, false, true); ?>
            </details>
            <?php endif; ?>

            <?php if ($instagram_enabled) : ?>
            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('Instagram performance', 'kimapa-content-assistant'); ?></summary>
                <p class="description"><?php esc_html_e('Without an Instagram API, these values can be maintained manually. The link is used as a reference for later evaluation.', 'kimapa-content-assistant'); ?></p>
                <p><label for="kimapa_ca_instagram_url"><?php esc_html_e('Instagram link', 'kimapa-content-assistant'); ?></label><input class="widefat kimapa-ca-copy-source" id="kimapa_ca_instagram_url" name="_kimapa_instagram_url" type="url" value="<?php echo esc_attr((string) $meta['_kimapa_instagram_url']); ?>" inputmode="url"><button type="button" class="button button-small kimapa-ca-copy" data-copy-target="#kimapa_ca_instagram_url"><?php esc_html_e('Copy Instagram link', 'kimapa-content-assistant'); ?></button></p>
                <div class="kimapa-ca-grid">
                    <?php foreach (['_kimapa_instagram_likes' => 'Likes', '_kimapa_instagram_comments' => 'Comments', '_kimapa_instagram_shares' => 'Shares', '_kimapa_instagram_saves' => 'Saves', '_kimapa_instagram_reach' => 'Reach', '_kimapa_instagram_impressions' => 'Impressions'] as $key => $label) : ?>
                        <p><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" type="number" min="0" step="1" value="<?php echo esc_attr((string) $meta[$key]); ?>" inputmode="numeric"></label></p>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>

            <details class="kimapa-ca-section">
                <summary><?php esc_html_e('Publication history', 'kimapa-content-assistant'); ?></summary>
                <button type="button" class="button button-small kimapa-ca-snapshot"><?php esc_html_e('Save publication-history snapshot', 'kimapa-content-assistant'); ?></button>
                <?php $this->render_publication_history((int) $post->ID); ?>
            </details>

            <?php if ($debug_enabled) { $this->render_extraction_debug((int) $post->ID, $meta); } ?>
            <button type="button" class="button button-primary kimapa-ca-save"><?php esc_html_e('Save', 'kimapa-content-assistant'); ?></button>
        </div>
        <?php
    }

    public function ajax_analyze(): void
    {
        $post_id = $this->validated_post_id();
        $prompt_language = isset($_POST['prompt_language']) ? sanitize_key(wp_unslash($_POST['prompt_language'])) : '';
        if (in_array($prompt_language, ['auto', 'de', 'en'], true)) {
            update_post_meta($post_id, '_content_assistant_prompt_language', $prompt_language);
        }
        $analysis = $this->analyzer->analyze($post_id);
        $prompt = $this->prompt_builder->build($post_id, $analysis);
        $this->meta->save_analysis($post_id, $analysis, $prompt);
        wp_send_json_success(['analysis' => $analysis, 'prompt' => $prompt]);
    }

    public function ajax_save(): void
    {
        $post_id = $this->validated_post_id();
        $this->meta->save_manual_fields($post_id, $_POST);
        wp_send_json_success(['message' => __('Saved.', 'kimapa-content-assistant')]);
    }

    public function ajax_snapshot(): void
    {
        $post_id = $this->validated_post_id();
        $this->meta->save_manual_fields($post_id, $_POST);
        $meta = $this->meta->get_all($post_id);
        $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : 'instagram';
        $snapshot_id = $this->publications->create_snapshot($post_id, $meta, $channel);
        wp_send_json_success(['message' => __('Snapshot saved.', 'kimapa-content-assistant'), 'snapshot_id' => $snapshot_id]);
    }

    private function validated_post_id(): int
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'kimapa-content-assistant')], 403);
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
        $structured = $this->analyzer->get_structured_facts_from_meta($post_id);
        $facts = json_decode((string) ($meta['_kimapa_extracted_facts'] ?? ''), true);
        $facts = is_array($facts) ? $facts : [];
        $meta_keys = $this->preview_post_meta_keys($post_id);
        ?>
        <details class="kimapa-ca-section kimapa-ca-debug">
            <summary><?php esc_html_e('Debug', 'kimapa-content-assistant'); ?></summary>
            <ul>
                <li><?php printf(esc_html__('Raw content available: %s', 'kimapa-content-assistant'), esc_html($debug['raw_content_present'] ? __('yes', 'kimapa-content-assistant') : __('no', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Cleaned content word count: %d', 'kimapa-content-assistant'), absint($debug['content_word_count'])); ?></li>
                <li><?php printf(esc_html__('Content Extraction Status: %s', 'kimapa-content-assistant'), esc_html((string) $debug['content_extraction_status'])); ?></li>
                <li><?php printf(esc_html__('Excerpt source: %s', 'kimapa-content-assistant'), esc_html($this->debug_excerpt_source($debug, $meta))); ?></li>
                <li><?php printf(esc_html__('Advertising disclosure detected: %s', 'kimapa-content-assistant'), esc_html(($meta['_kimapa_advertising_disclosure_detected'] ?? '') === '1' ? __('yes', 'kimapa-content-assistant') : __('no', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Relative time terms found: %s', 'kimapa-content-assistant'), esc_html($relative_terms ? implode(', ', $relative_terms) : '–')); ?></li>
                <li><?php printf(esc_html__('Potentially outdated terms found: %s', 'kimapa-content-assistant'), esc_html($outdated_terms ? implode(', ', $outdated_terms) : '–')); ?></li>
                <li><?php printf(esc_html__('Dates without year found: %s', 'kimapa-content-assistant'), esc_html($dates_without_year ? implode(', ', $dates_without_year) : '–')); ?></li>
                <li><?php printf(esc_html__('Structured fields enabled: %s', 'kimapa-content-assistant'), esc_html($structured['enabled'] ? __('yes', 'kimapa-content-assistant') : __('no', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Fallback to content parsing: %s', 'kimapa-content-assistant'), esc_html($structured['fallback_to_content_parsing'] ? __('yes', 'kimapa-content-assistant') : __('no', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Structured values: %s', 'kimapa-content-assistant'), esc_html(wp_json_encode(array_intersect_key($structured, array_flip(['latitude','longitude','google_maps_link','street','zip','city','external_link','image_credit']))))); ?></li>
                <li><?php printf(esc_html__('Facts Source: %s', 'kimapa-content-assistant'), esc_html(wp_json_encode($facts['facts_source'] ?? []))); ?></li>
                <li><?php printf(esc_html__('Internal notes detected: %s', 'kimapa-content-assistant'), esc_html(!empty($facts['internal_editorial_notes_detected']) ? __('yes', 'kimapa-content-assistant') : __('no', 'kimapa-content-assistant'))); ?></li>
                <li><?php printf(esc_html__('Editorial note terms: %s', 'kimapa-content-assistant'), esc_html(!empty($facts['detected_editorial_note_terms']) ? implode(', ', (array) $facts['detected_editorial_note_terms']) : '–')); ?></li>
            </ul>
            <details><summary><?php esc_html_e('Show existing post meta keys', 'kimapa-content-assistant'); ?></summary><?php $this->render_meta_key_preview($meta_keys); ?></details>
        </details>
        <?php
    }

    private function textarea(string $key, string $label, array $meta, int $rows = 3, bool $readonly = false, bool $copy_button = false): void
    {
        $id = 'kimapa_ca_' . trim($key, '_');
        printf('<p class="kimapa-ca-field"><label for="%1$s">%2$s</label><textarea class="widefat kimapa-ca-copy-source" id="%1$s" name="%3$s" rows="%4$d"%5$s>%6$s</textarea>', esc_attr($id), esc_html($label), esc_attr($key), absint($rows), $readonly ? ' readonly' : '', esc_textarea((string) ($meta[$key] ?? '')));
        if ($copy_button) {
            printf('<button type="button" class="button button-small kimapa-ca-copy" data-copy-target="#%1$s">%2$s</button>', esc_attr($id), esc_html(sprintf(__('Copy %s', 'kimapa-content-assistant'), $label)));
        }
        echo '</p>';
    }


    private function render_publication_history(int $post_id): void
    {
        $items = $this->publications->latest($post_id, 5);
        if (!$items) {
            echo '<p class="description">' . esc_html__('No snapshots saved yet.', 'kimapa-content-assistant') . '</p>';
            return;
        }
        echo '<ul class="kimapa-ca-history">';
        foreach ($items as $item) {
            printf('<li><strong>%1$s</strong> · %2$s<br><span>%3$s</span></li>', esc_html((string) $item['channel']), esc_html((string) $item['created_at']), esc_html(wp_trim_words((string) $item['content'], 12)));
        }
        echo '</ul>';
    }

    private function settings_text(string $name, string $label, $value, string $type = 'text'): void
    {
        printf('<p><label><strong>%1$s</strong><br><input class="regular-text" type="%2$s" name="%3$s" value="%4$s"></label></p>', esc_html($label), esc_attr($type), esc_attr($name), esc_attr((string) $value));
    }

    private function settings_textarea(string $name, string $label, $value, int $rows): void
    {
        printf('<p><label><strong>%1$s</strong><br><textarea class="large-text" rows="%2$d" name="%3$s">%4$s</textarea></label></p>', esc_html($label), absint($rows), esc_attr($name), esc_textarea((string) $value));
    }

    private function settings_select(string $name, string $label, string $value, array $options): void
    {
        echo '<p><label><strong>' . esc_html($label) . '</strong><br><select name="' . esc_attr($name) . '">';
        foreach ($options as $key => $option_label) {
            printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($key), selected($value, $key, false), esc_html($option_label));
        }
        echo '</select></label></p>';
    }


    private function language_options(): array
    {
        return [
            'auto' => __('Auto', 'kimapa-content-assistant'),
            'de' => __('German', 'kimapa-content-assistant'),
            'en' => __('English', 'kimapa-content-assistant'),
        ];
    }

    private function profile_options(array $config): array
    {
        $options = [];
        foreach ((array) ($config['content_profiles'] ?? []) as $key => $profile) {
            $options[$key] = (string) ($profile['label'] ?? $key);
        }
        return $options ?: ['generic_editorial' => 'Generic Editorial'];
    }

    private function score_label_from_score(int $score): string
    {
        foreach ((array) $this->config->get('scoring.labels', []) as $label) {
            if ($score >= (int) ($label['min'] ?? 0) && $score <= (int) ($label['max'] ?? 100)) {
                return (string) ($label['text'] ?? '');
            }
        }
        return __('No analysis yet', 'kimapa-content-assistant');
    }



    private function check_has_findings(array $checks, string $key): bool
    {
        foreach ($checks as $check) {
            if (($check['key'] ?? '') === $key && empty($check['passed'])) {
                return true;
            }
        }
        return false;
    }

    private function normalize_unicode_data($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'normalize_unicode_data'], $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        return (string) preg_replace_callback('/\\\\?u([0-9a-fA-F]{4})/', static function ($matches) {
            $decoded = json_decode('"\\u' . $matches[1] . '"');
            return is_string($decoded) ? $decoded : $matches[0];
        }, $value);
    }

    private function preview_post_meta_keys(int $post_id): array
    {
        $all = get_post_meta($post_id);
        $preview = [];
        foreach ($all as $key => $values) {
            $value = is_array($values) ? reset($values) : $values;
            if (is_array($value) || is_object($value)) {
                $value = '[complex value]';
            }
            $preview[$key] = mb_substr((string) $value, 0, 80);
        }
        ksort($preview);
        return $preview;
    }

    private function render_meta_key_preview(array $items): void
    {
        if (!$items) {
            echo '<p class="description">' . esc_html__('No post meta keys found.', 'kimapa-content-assistant') . '</p>';
            return;
        }
        echo '<ul class="kimapa-ca-meta-keys">';
        foreach ($items as $key => $value) {
            printf('<li><code>%1$s</code>: <span>%2$s</span></li>', esc_html((string) $key), esc_html((string) $value));
        }
        echo '</ul>';
    }

    private function render_checks(array $checks): void
    {
        if (!$checks) {
            echo '<li>' . esc_html__('No analysis has been run yet.', 'kimapa-content-assistant') . '</li>';
            return;
        }
        foreach ($checks as $check) {
            $class = !empty($check['passed']) ? 'is-passed' : 'is-open';
            printf('<li class="%1$s"><strong>%2$s</strong><br><span>%3$s</span>', esc_attr($class), esc_html((string) ($check['label'] ?? '')), esc_html((string) ($check['message'] ?? '')));
            $this->render_check_extra($check);
            echo '</li>';
        }
    }


    private function render_check_extra(array $check): void
    {
        foreach (['detected_editorial_note_terms' => __('Detected terms', 'kimapa-content-assistant'), 'detected_editorial_note_snippets' => __('Snippets', 'kimapa-content-assistant'), 'detected_typo_hints' => __('Typo hints', 'kimapa-content-assistant')] as $field => $label) {
            if (empty($check[$field]) || !is_array($check[$field])) {
                continue;
            }
            echo '<details class="kimapa-ca-check-extra"><summary>' . esc_html($label) . '</summary><ul>';
            foreach (array_slice($check[$field], 0, 10) as $item) {
                if (is_array($item)) {
                    $text = ($item['found'] ?? '') . ' → ' . ($item['suggestion'] ?? '');
                } else {
                    $text = (string) $item;
                }
                echo '<li>' . esc_html($text) . '</li>';
            }
            echo '</ul></details>';
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
            return __('Placeholder', 'kimapa-content-assistant');
        }
        return (string) $debug['excerpt_source'];
    }
}
