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
	 * Run a read-only AI analysis for one post and store the recommendation data.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function analyze_with_ai( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! self::is_configured() ) {
			return new WP_Error( 'cto_ai_not_configured', __( 'AI analysis is not configured or enabled.', 'content-taxonomy-overview' ) );
		}

		if ( ! $post || ! in_array( $post->post_type, CTO_Utils::supported_post_types(), true ) ) {
			return new WP_Error( 'cto_ai_invalid_post', __( 'Unsupported post for AI analysis.', 'content-taxonomy-overview' ) );
		}

		$settings = self::get_settings();
		$payload  = $this->build_payload( $post, $settings );
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : sprintf( 'OpenAI API returned HTTP %d.', (int) $code );
			return new WP_Error( 'cto_ai_http_error', $message );
		}

		$content = isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '';
		$result  = $this->parse_ai_response( $content );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['post_id']              = $post_id;
		$result['model']                = $settings['model'];
		$result['analyzed_at']          = current_time( 'mysql' );
		$result['automation_performed'] = false;

		update_post_meta( $post_id, '_cto_ai_analysis_data', $result );
		update_post_meta( $post_id, '_cto_ai_analyzed_at', $result['analyzed_at'] );

		return $result;
	}

	/**
	 * Build the OpenAI chat completions payload.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $settings AI settings.
	 * @return array
	 */
	private function build_payload( $post, $settings ) {
		$content = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, (int) $settings['max_chars'] ) : substr( $content, 0, (int) $settings['max_chars'] );

		$context = array(
			'title'             => get_the_title( $post ),
			'post_type'         => $post->post_type,
			'existing_terms'    => $this->get_existing_terms( $post ),
			'available_terms'   => $this->get_available_terms( $post->post_type ),
			'content_excerpt'   => $content,
			'important_notice'  => 'Return recommendations only. Do not imply that WordPress content, categories, tags, or taxonomies have been changed.',
		);

		return array(
			'model'           => $settings['model'],
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'You are a careful WordPress content strategist. Analyze the supplied post/page and return strict JSON only with these keys: main_topic, recommended_categories, recommended_tags, recommended_taxonomies, content_cluster, search_intent, internal_link_suggestions, tone_assessment, reasoning. Use short German recommendations. Never claim to modify content or taxonomy assignments.',
				),
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $context ),
				),
			),
		);
	}

	/**
	 * Parse and normalize the AI JSON response.
	 *
	 * @param string $content Raw content.
	 * @return array|WP_Error
	 */
	private function parse_ai_response( $content ) {
		$data = json_decode( (string) $content, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cto_ai_invalid_json', __( 'AI response was not valid JSON.', 'content-taxonomy-overview' ) );
		}

		return array(
			'main_topic'                => isset( $data['main_topic'] ) ? sanitize_text_field( $data['main_topic'] ) : '',
			'recommended_categories'    => $this->sanitize_string_list( isset( $data['recommended_categories'] ) ? $data['recommended_categories'] : array() ),
			'recommended_tags'          => $this->sanitize_string_list( isset( $data['recommended_tags'] ) ? $data['recommended_tags'] : array() ),
			'recommended_taxonomies'    => $this->sanitize_taxonomy_recommendations( isset( $data['recommended_taxonomies'] ) ? $data['recommended_taxonomies'] : array() ),
			'content_cluster'           => isset( $data['content_cluster'] ) ? sanitize_text_field( $data['content_cluster'] ) : '',
			'search_intent'             => isset( $data['search_intent'] ) ? sanitize_text_field( $data['search_intent'] ) : '',
			'internal_link_suggestions' => $this->sanitize_string_list( isset( $data['internal_link_suggestions'] ) ? $data['internal_link_suggestions'] : array() ),
			'tone_assessment'           => isset( $data['tone_assessment'] ) ? sanitize_text_field( $data['tone_assessment'] ) : '',
			'reasoning'                 => isset( $data['reasoning'] ) ? sanitize_textarea_field( $data['reasoning'] ) : '',
		);
	}

	/**
	 * Sanitize a list of strings.
	 *
	 * @param mixed $items Items.
	 * @return string[]
	 */
	private function sanitize_string_list( $items ) {
		if ( is_string( $items ) ) {
			$items = array( $items );
		}

		if ( ! is_array( $items ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $items as $item ) {
			if ( is_scalar( $item ) ) {
				$sanitized[] = sanitize_text_field( (string) $item );
			} elseif ( is_array( $item ) ) {
				$sanitized[] = sanitize_text_field( wp_json_encode( $item ) );
			}
		}

		$sanitized = array_filter( $sanitized );
		return array_values( array_slice( $sanitized, 0, 20 ) );
	}

	/**
	 * Sanitize custom taxonomy recommendations.
	 *
	 * @param mixed $items Recommendation items.
	 * @return array
	 */
	private function sanitize_taxonomy_recommendations( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $items as $key => $value ) {
			if ( is_array( $value ) ) {
				$sanitized[ sanitize_key( $key ) ] = $this->sanitize_string_list( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Get assigned terms grouped by taxonomy.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function get_existing_terms( $post ) {
		$existing = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $taxonomy_object ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$terms = array();
			}
			$existing[ $taxonomy_object->labels->name ] = wp_list_pluck( $terms, 'name' );
		}

		return $existing;
	}

	/**
	 * Get available term names for relevant taxonomies.
	 *
	 * @param string $post_type Post type.
	 * @return array
	 */
	private function get_available_terms( $post_type ) {
		$available = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy => $taxonomy_object ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 50,
					'fields'     => 'names',
				)
			);

			if ( ! is_wp_error( $terms ) ) {
				$available[ $taxonomy_object->labels->name ] = $terms;
			}
		}

		return $available;
	}
}
