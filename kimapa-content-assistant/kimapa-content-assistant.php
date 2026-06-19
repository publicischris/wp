<?php
/**
 * Plugin Name: KiMaPa Content Assistant
 * Description: Redaktionelle Beitragsanalyse, Social-Media-Prompt-Erstellung und manuelle Instagram-Performance-Dokumentation für KiMaPa.
 * Version: 0.1.0
 * Author: KiMaPa
 * Text Domain: kimapa-content-assistant
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KIMAPA_CA_VERSION', '0.1.0');
define('KIMAPA_CA_FILE', __FILE__);
define('KIMAPA_CA_DIR', plugin_dir_path(__FILE__));
define('KIMAPA_CA_URL', plugin_dir_url(__FILE__));

require_once KIMAPA_CA_DIR . 'includes/class-config.php';
require_once KIMAPA_CA_DIR . 'includes/class-meta.php';
require_once KIMAPA_CA_DIR . 'includes/class-content-extractor.php';
require_once KIMAPA_CA_DIR . 'includes/class-analyzer.php';
require_once KIMAPA_CA_DIR . 'includes/class-prompt-builder.php';
require_once KIMAPA_CA_DIR . 'includes/class-admin.php';
require_once KIMAPA_CA_DIR . 'includes/class-plugin.php';

add_action('plugins_loaded', static function () {
    KiMaPa_Content_Assistant\Plugin::instance()->init();
});
