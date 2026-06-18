<?php
/**
 * Utility helpers.
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared utility methods.
 */
class CTO_Utils {
	const ENABLED_POST_TYPES_OPTION = 'cto_enabled_post_types';
	const CRITERIA_SETTINGS_OPTION  = 'cto_post_type_criteria_settings';

	/**
	 * Analysis post meta keys.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			'_cto_taxonomy_score',
			'_cto_structure_score',
			'_cto_total_score',
			'_cto_analysis_status',
			'_cto_analysis_data',
			'_cto_analyzed_at',
			'_cto_ai_enabled',
			'_cto_ai_analyzed_at',
			'_cto_ai_status',
			'_cto_ai_error',
			'_cto_ai_main_topic',
			'_cto_ai_content_cluster',
			'_cto_ai_search_intent',
			'_cto_ai_target_audience',
			'_cto_ai_recommended_categories',
			'_cto_ai_recommended_tags',
			'_cto_ai_recommended_custom_taxonomies',
			'_cto_ai_internal_link_suggestions',
			'_cto_ai_tone_assessment',
			'_cto_ai_summary',
			'_cto_ai_recommendations',
			'_cto_ai_raw_response',
			'_cto_ai_analysis_data',
			'_cto_ai_recommendation_status',
		);
	}

	/**
	 * Get supported post types.
	 *
	 * @return string[]
	 */
	public static function supported_post_types() {
		$available_post_types = array_keys( self::available_post_type_objects() );
		$saved                = get_option( self::ENABLED_POST_TYPES_OPTION, array() );
		$saved                = is_array( $saved ) ? array_map( 'sanitize_key', $saved ) : array();

		if ( empty( $saved ) ) {
			$settings = get_option( 'cto_ai_settings', array() );
			$saved    = isset( $settings['enabled_post_types'] ) && is_array( $settings['enabled_post_types'] ) ? array_map( 'sanitize_key', $settings['enabled_post_types'] ) : array();
		}

		if ( empty( $saved ) ) {
			$saved = array( 'post', 'page' );
		}

		$post_types = array_values( array_intersect( $saved, $available_post_types ) );
		if ( empty( $post_types ) ) {
			$post_types = array_values( array_intersect( array( 'post', 'page' ), $available_post_types ) );
		}

		return apply_filters( 'cto_supported_post_types', $post_types );
	}

	/**
	 * Get public/admin-visible post types that make sense for content checks.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function available_post_type_objects() {
		if ( ! function_exists( 'get_post_types' ) ) {
			return array();
		}

		$objects  = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );
		$excluded = array( 'attachment', 'revision', 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );

		foreach ( $excluded as $post_type ) {
			unset( $objects[ $post_type ] );
		}

		return is_array( $objects ) ? $objects : array();
	}

	/**
	 * Get supported post type objects keyed by slug.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function supported_post_type_objects() {
		$objects = array();

		foreach ( self::supported_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object ) {
				$objects[ $post_type ] = $object;
			}
		}

		return $objects;
	}

	/**
	 * Criteria keys and labels.
	 *
	 * @return array
	 */
	public static function criteria_definitions() {
		return array(
			'category'           => __( 'Kategorie vorhanden', 'content-taxonomy-overview' ),
			'tag'                => __( 'Tag vorhanden', 'content-taxonomy-overview' ),
			'custom_taxonomy'    => __( 'Custom Taxonomy vorhanden', 'content-taxonomy-overview' ),
			'word_count'         => __( 'Mehr als 500 Wörter', 'content-taxonomy-overview' ),
			'h2'                 => __( 'H2 vorhanden', 'content-taxonomy-overview' ),
			'featured_image'     => __( 'Featured Image vorhanden', 'content-taxonomy-overview' ),
			'internal_link'      => __( 'Interner Link vorhanden', 'content-taxonomy-overview' ),
			'meta_description'   => __( 'Meta Description vorhanden', 'content-taxonomy-overview' ),
			'featured_image_alt' => __( 'Featured Image ALT-Text vorhanden', 'content-taxonomy-overview' ),
		);
	}

	/**
	 * Get criteria settings for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function get_post_type_criteria_settings( $post_type ) {
		$post_type = sanitize_key( $post_type );
		$saved     = get_option( self::CRITERIA_SETTINGS_OPTION, array() );
		$saved     = is_array( $saved ) ? $saved : array();
		$defaults  = self::default_criteria_for_post_type( $post_type );
		$settings  = isset( $saved[ $post_type ] ) && is_array( $saved[ $post_type ] ) ? $saved[ $post_type ] : array();
		$merged    = array();

		foreach ( self::criteria_definitions() as $criterion => $label ) {
			$value                 = isset( $settings[ $criterion ] ) ? sanitize_key( $settings[ $criterion ] ) : $defaults[ $criterion ];
			$merged[ $criterion ] = in_array( $value, array( 'check', 'not_relevant' ), true ) ? $value : $defaults[ $criterion ];
		}

		return $merged;
	}

	/**
	 * Default criteria settings.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function default_criteria_for_post_type( $post_type ) {
		$defaults = array_fill_keys( array_keys( self::criteria_definitions() ), 'check' );

		if ( 'page' === $post_type ) {
			$defaults['category']           = 'not_relevant';
			$defaults['tag']                = 'not_relevant';
			$defaults['featured_image']     = 'not_relevant';
			$defaults['featured_image_alt'] = 'not_relevant';
		}

		return $defaults;
	}

	/**
	 * Get supported statuses.
	 *
	 * @return string[]
	 */
	public static function supported_statuses() {
		return array( 'publish', 'draft' );
	}

	/**
	 * Convert a numeric score to a status label.
	 *
	 * @param int|float $score Score.
	 * @return string
	 */
	public static function status_from_score( $score ) {
		$score = (int) round( $score );

		if ( $score >= 80 ) {
			return 'OK';
		}

		if ( $score >= 50 ) {
			return 'Prüfen';
		}

		return 'Unvollständig';
	}

	/**
	 * Mask an API key for display.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask_api_key( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return '';
		}

		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '•', strlen( $key ) );
		}

		return substr( $key, 0, 3 ) . str_repeat( '•', max( 0, strlen( $key ) - 7 ) ) . substr( $key, -4 );
	}
}
