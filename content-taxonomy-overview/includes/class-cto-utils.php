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
		if ( ! function_exists( 'get_post_types' ) ) {
			return array( 'post', 'page' );
		}

		$post_types = get_post_types( array( 'show_ui' => true ), 'names' );
		$post_types = is_array( $post_types ) ? $post_types : array();
		unset( $post_types['attachment'] );

		$post_types = array_values( array_unique( array_merge( array( 'post', 'page' ), array_values( $post_types ) ) ) );

		return apply_filters( 'cto_supported_post_types', $post_types );
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
