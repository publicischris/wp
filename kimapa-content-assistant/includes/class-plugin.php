<?php
namespace KiMaPa_Content_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static $instance;

    /** @var Config */
    private $config;

    /** @var Meta */
    private $meta;

    /** @var Content_Extractor */
    private $content_extractor;

    /** @var Analyzer */
    private $analyzer;

    /** @var Prompt_Builder */
    private $prompt_builder;

    /** @var Admin */
    private $admin;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        $this->config = new Config(KIMAPA_CA_DIR . 'config/kimapa-content-assistant.config.json');
        $this->meta = new Meta();
        $this->content_extractor = new Content_Extractor();
        $this->analyzer = new Analyzer($this->config, $this->content_extractor);
        $this->prompt_builder = new Prompt_Builder($this->config, $this->content_extractor);
        $this->admin = new Admin($this->config, $this->meta, $this->analyzer, $this->prompt_builder, $this->content_extractor);
        $this->admin->init();
    }
}
