<?php
/**
 * Main plugin bootstrap.
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class CTO_Plugin {
	/** @var CTO_Plugin|null */
	private static $instance = null;

	/** @var CTO_Analyzer */
	private $analyzer;

	/**
	 * Get instance.
	 *
	 * @return CTO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->analyzer = new CTO_Analyzer();
		new CTO_Admin( $this->analyzer );
		new CTO_Settings();

		add_action( 'save_post', array( $this, 'analyze_on_save' ), 20, 3 );
	}

	/**
	 * Analyze posts/pages on save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param bool    $update Whether update.
	 * @return void
	 */
	public function analyze_on_save( $post_id, $post, $update ) {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post || ! in_array( $post->post_type, CTO_Utils::supported_post_types(), true ) || ! in_array( $post->post_status, CTO_Utils::supported_statuses(), true ) ) {
			return;
		}

		$this->analyzer->analyze_post( $post_id );
	}
}
