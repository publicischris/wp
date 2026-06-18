<?php
/**
 * Plugin Name: Content Compass
 * Plugin URI:  https://example.com/content-taxonomy-overview
 * Description: Rule-based overview for post/page taxonomy quality and content structure, with prepared optional AI analysis settings.
 * Version:     1.2.0
 * Author:      Content Compass
 * Text Domain: content-taxonomy-overview
 * Domain Path: /languages
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CTO_VERSION', '1.2.0' );
define( 'CTO_PLUGIN_FILE', __FILE__ );
define( 'CTO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CTO_PLUGIN_DIR . 'includes/class-cto-utils.php';
require_once CTO_PLUGIN_DIR . 'includes/class-cto-ai-service.php';
require_once CTO_PLUGIN_DIR . 'includes/class-cto-analyzer.php';
require_once CTO_PLUGIN_DIR . 'includes/class-cto-settings.php';
require_once CTO_PLUGIN_DIR . 'includes/class-cto-admin.php';
require_once CTO_PLUGIN_DIR . 'includes/class-cto-plugin.php';

add_action( 'plugins_loaded', array( 'CTO_Plugin', 'instance' ) );
