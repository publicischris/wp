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
			'_cto_ai_analysis_data',
			'_cto_ai_analyzed_at',
		);
	}

	/**
	 * Get supported post types.
	 *
	 * @return string[]
	 */
	public static function supported_post_types() {
		return array( 'post', 'page' );
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
