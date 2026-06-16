<?php
/**
 * AI service placeholder and API test integration.
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepared service for optional future AI analysis.
 */
class CTO_AI_Service {
	const OPTION_KEY = 'cto_ai_settings';

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'          => '',
			'model'            => 'gpt-4.1-mini',
			'enabled'          => 0,
			'max_chars'        => 6000,
			'connection_valid' => 0,
			'tested_at'        => '',
		);
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::defaults() );
	}

	/**
	 * Save sanitized settings.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public static function save_settings( $settings ) {
		$current = self::get_settings();
		$api_key = isset( $settings['api_key'] ) ? sanitize_text_field( wp_unslash( $settings['api_key'] ) ) : $current['api_key'];
		if ( '' === $api_key || '••••••••' === $api_key ) {
			$api_key = $current['api_key'];
		}

		$next = array(
			'api_key'          => $api_key,
			'model'            => isset( $settings['model'] ) ? sanitize_text_field( wp_unslash( $settings['model'] ) ) : $current['model'],
			'enabled'          => empty( $settings['enabled'] ) ? 0 : 1,
			'max_chars'        => isset( $settings['max_chars'] ) ? max( 500, absint( $settings['max_chars'] ) ) : $current['max_chars'],
			'connection_valid' => ( '' !== $api_key && $api_key === $current['api_key'] ) ? (int) $current['connection_valid'] : 0,
			'tested_at'        => ( '' !== $api_key && $api_key === $current['api_key'] ) ? $current['tested_at'] : '',
		);

		update_option( self::OPTION_KEY, $next, false );
	}

	/**
	 * Whether AI can be called.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] ) && ! empty( $settings['api_key'] ) && ( ! empty( $settings['connection_valid'] ) || 0 === strpos( $settings['api_key'], 'sk-' ) );
	}

	/**
	 * Test API connection without analyzing content.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$settings = self::get_settings();
		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'cto_missing_api_key', __( 'No API key is saved.', 'content-taxonomy-overview' ) );
		}

		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['api_key'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'cto_openai_http_error', sprintf( 'OpenAI API returned HTTP %d.', (int) $code ) );
		}

		$settings['connection_valid'] = 1;
		$settings['tested_at']        = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $settings, false );

		return true;
	}

	/**
	 * Placeholder for future AI analysis.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function analyze_with_ai( $post_id ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'cto_ai_not_configured', __( 'AI analysis is not configured or enabled.', 'content-taxonomy-overview' ) );
		}

		return array(
			'post_id'                    => absint( $post_id ),
			'main_topic'                 => '',
			'recommended_categories'     => array(),
			'recommended_tags'           => array(),
			'recommended_taxonomies'     => array(),
			'content_cluster'            => '',
			'search_intent'              => '',
			'internal_link_suggestions'  => array(),
			'tone_assessment'            => '',
			'reasoning'                  => '',
			'automation_performed'       => false,
		);
	}
}
