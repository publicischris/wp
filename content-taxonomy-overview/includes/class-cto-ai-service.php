<?php
/**
 * AI service and API integration.
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for optional, read-only AI analysis.
 */
class CTO_AI_Service {
	const OPTION_KEY = 'cto_ai_settings';
	const LOG_OPTION = 'cto_ai_log';

	/** Get default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'                   => '',
			'model'                     => 'gpt-4.1-mini',
			'enabled'                   => 0,
			'max_chars'                 => 6000,
			'connection_valid'          => 0,
			'tested_at'                 => '',
			'save_results'              => 1,
			'manual_only'               => 1,
			'auto_after_rule'           => 0,
			'allow_create_terms'        => 0,
			'allow_create_custom_terms' => 0,
		);
	}

	/** Get settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::defaults() );
	}

	/** Save sanitized settings.
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

		if ( ! empty( $settings['remove_api_key'] ) ) {
			$api_key = '';
		}

		$next = array(
			'api_key'                   => $api_key,
			'model'                     => isset( $settings['model'] ) ? sanitize_text_field( wp_unslash( $settings['model'] ) ) : $current['model'],
			'enabled'                   => empty( $settings['enabled'] ) ? 0 : 1,
			'max_chars'                 => isset( $settings['max_chars'] ) ? max( 500, absint( $settings['max_chars'] ) ) : $current['max_chars'],
			'connection_valid'          => ( '' !== $api_key && $api_key === $current['api_key'] ) ? (int) $current['connection_valid'] : 0,
			'tested_at'                 => ( '' !== $api_key && $api_key === $current['api_key'] ) ? $current['tested_at'] : '',
			'save_results'              => empty( $settings['save_results'] ) ? 0 : 1,
			'manual_only'               => empty( $settings['manual_only'] ) ? 0 : 1,
			'auto_after_rule'           => empty( $settings['auto_after_rule'] ) ? 0 : 1,
			'allow_create_terms'        => empty( $settings['allow_create_terms'] ) ? 0 : 1,
			'allow_create_custom_terms' => empty( $settings['allow_create_custom_terms'] ) ? 0 : 1,
		);

		if ( '' === $next['model'] ) {
			$next['model'] = self::defaults()['model'];
		}

		update_option( self::OPTION_KEY, $next, false );
		if ( ! empty( $settings['remove_api_key'] ) ) {
			self::log( 'api_key_removed', 0, 'API key removed.' );
		}
	}

	/** Whether AI can be called.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] ) && ! empty( $settings['api_key'] ) && ! empty( $settings['model'] ) && ( ! empty( $settings['connection_valid'] ) || 0 === strpos( $settings['api_key'], 'sk-' ) );
	}

	/** Test API connection without analyzing content.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$settings = self::get_settings();
		if ( empty( $settings['api_key'] ) ) {
			self::log( 'api_test_failed', 0, 'Missing API key.' );
			return new WP_Error( 'cto_missing_api_key', __( 'No API key is saved.', 'content-taxonomy-overview' ) );
		}

		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $settings['api_key'] ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'api_test_failed', 0, $response->get_error_message() );
			return new WP_Error( 'cto_openai_request_failed', __( 'The API request failed or timed out.', 'content-taxonomy-overview' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			self::log( 'api_test_failed', 0, 'HTTP ' . (int) $code );
			return new WP_Error( 'cto_openai_http_error', sprintf( __( 'OpenAI API returned HTTP %d.', 'content-taxonomy-overview' ), (int) $code ) );
		}

		$settings['connection_valid'] = 1;
		$settings['tested_at']        = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $settings, false );
		self::log( 'api_test_success', 0, 'API connection tested.' );

		return true;
	}

	/** Backward-compatible method name.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function analyze_with_ai( $post_id ) {
		return $this->analyze_post_with_ai( $post_id );
	}

	/** Run a read-only AI analysis for one post and store recommendation data.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function analyze_post_with_ai( $post_id ) {
		$post_id  = absint( $post_id );
		$post     = get_post( $post_id );
		$settings = self::get_settings();

		if ( ! self::is_configured() ) {
			$this->store_error( $post_id, __( 'AI analysis is disabled or not configured.', 'content-taxonomy-overview' ) );
			return new WP_Error( 'cto_ai_not_configured', __( 'AI analysis is disabled or not configured.', 'content-taxonomy-overview' ) );
		}

		if ( ! $post || ! in_array( $post->post_type, CTO_Utils::supported_post_types(), true ) ) {
			$this->store_error( $post_id, __( 'Post not found or unsupported.', 'content-taxonomy-overview' ) );
			return new WP_Error( 'cto_ai_invalid_post', __( 'Post not found or unsupported.', 'content-taxonomy-overview' ) );
		}

		self::log( 'ai_analysis_started', $post_id, 'AI analysis started.' );
		update_post_meta( $post_id, '_cto_ai_enabled', 1 );
		update_post_meta( $post_id, '_cto_ai_status', 'running' );
		delete_post_meta( $post_id, '_cto_ai_error' );

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $this->build_payload( $post, $settings ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->store_error( $post_id, __( 'The AI request failed or timed out.', 'content-taxonomy-overview' ) );
			return new WP_Error( 'cto_ai_request_failed', __( 'The AI request failed or timed out.', 'content-taxonomy-overview' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? sanitize_text_field( $body['error']['message'] ) : sprintf( __( 'OpenAI API returned HTTP %d.', 'content-taxonomy-overview' ), (int) $code );
			$this->store_error( $post_id, $message );
			return new WP_Error( 'cto_ai_http_error', $message );
		}

		$content = isset( $body['choices'][0]['message']['content'] ) ? trim( (string) $body['choices'][0]['message']['content'] ) : '';
		if ( '' === $content ) {
			$this->store_error( $post_id, __( 'The AI response was empty.', 'content-taxonomy-overview' ) );
			return new WP_Error( 'cto_ai_empty_response', __( 'The AI response was empty.', 'content-taxonomy-overview' ) );
		}

		$result = $this->parse_ai_response( $content );
		if ( is_wp_error( $result ) ) {
			$this->store_error( $post_id, $result->get_error_message() );
			return $result;
		}

		$result = $this->filter_result_for_post_type( $post, $result );

		if ( ! empty( $settings['save_results'] ) ) {
			$this->store_result( $post_id, $result, $content );
		}

		self::log( 'ai_analysis_success', $post_id, 'AI analysis completed.' );
		return $result;
	}

	/** Build the OpenAI chat completions payload.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $settings AI settings.
	 * @return array
	 */
	private function build_payload( $post, $settings ) {
		$content = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, (int) $settings['max_chars'] ) : substr( $content, 0, (int) $settings['max_chars'] );
		$context = array(
			'title'                    => get_the_title( $post ),
			'post_id'                  => $post->ID,
			'post_type'                => $post->post_type,
			'existing_terms'           => $this->get_existing_terms( $post ),
			'available_terms'          => $this->get_available_terms( $post->post_type ),
			'registered_taxonomies'    => $this->get_registered_taxonomies_context( $post->post_type ),
			'rule_based_scores'        => array(
				'taxonomy'  => get_post_meta( $post->ID, '_cto_taxonomy_score', true ),
				'structure' => get_post_meta( $post->ID, '_cto_structure_score', true ),
				'total'     => get_post_meta( $post->ID, '_cto_total_score', true ),
				'status'    => get_post_meta( $post->ID, '_cto_analysis_status', true ),
			),
			'internal_link_candidates' => $this->get_internal_link_candidates( $post ),
			'content_excerpt'          => $content,
		);

		$schema_description = '{"main_topic":"string","content_cluster":"string","search_intent":"informational|commercial|transactional|navigational|mixed|unknown","target_audience":"string","recommended_categories":[{"name":"string","reason":"string","confidence":0.0}],"recommended_tags":[{"name":"string","reason":"string","confidence":0.0}],"recommended_custom_taxonomies":[{"taxonomy":"string","terms":[{"name":"string","reason":"string","confidence":0.0}]}],"internal_link_suggestions":[{"post_id":123,"title":"string","url":"string","reason":"string","confidence":0.0}],"tone_assessment":{"summary":"string","strengths":["string"],"risks":["string"]},"summary":"string","recommendations":[{"type":"taxonomy|structure|content|linking|tone","priority":"low|medium|high","recommendation":"string","reason":"string"}]}';

		return array(
			'model'           => $settings['model'],
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => 'Du bist ein vorsichtiger WordPress-Content-Stratege. Analysiere nur und gib ausschließlich valides JSON zurück. Verändere keine Inhalte, entscheide nichts automatisch und behaupte niemals, Taxonomien oder Inhalte geändert zu haben. Empfiehl Kategorien nur, wenn die Taxonomie category im Kontext registriert ist; empfiehl Tags nur, wenn post_tag registriert ist; Custom-Taxonomy-Empfehlungen dürfen ausschließlich die im Kontext genannten registrierten Taxonomie-Slugs verwenden. Nutze kurze deutsche Empfehlungen mit Begründung. Das JSON muss diesem Schema entsprechen: ' . $schema_description,
				),
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $context ),
				),
			),
		); }

	/** Parse and normalize the AI JSON response.
	 *
	 * @param string $content Raw content.
	 * @return array|WP_Error
	 */
	private function parse_ai_response( $content ) {
		$data = json_decode( (string) $content, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cto_ai_invalid_json', __( 'The AI response was not valid JSON.', 'content-taxonomy-overview' ) );
		}
		return array(
			'main_topic'                       => sanitize_text_field( $data['main_topic'] ?? '' ),
			'content_cluster'                  => sanitize_text_field( $data['content_cluster'] ?? '' ),
			'search_intent'                    => $this->sanitize_choice( $data['search_intent'] ?? 'unknown', array( 'informational', 'commercial', 'transactional', 'navigational', 'mixed', 'unknown' ) ),
			'target_audience'                  => sanitize_text_field( $data['target_audience'] ?? '' ),
			'recommended_categories'           => $this->sanitize_recommendation_items( $data['recommended_categories'] ?? array() ),
			'recommended_tags'                  => $this->sanitize_recommendation_items( $data['recommended_tags'] ?? array() ),
			'recommended_custom_taxonomies'     => $this->sanitize_custom_taxonomy_items( $data['recommended_custom_taxonomies'] ?? array() ),
			'internal_link_suggestions'         => $this->sanitize_link_items( $data['internal_link_suggestions'] ?? array() ),
			'tone_assessment'                  => $this->sanitize_tone( $data['tone_assessment'] ?? array() ),
			'summary'                          => sanitize_textarea_field( $data['summary'] ?? '' ),
			'recommendations'                  => $this->sanitize_general_recommendations( $data['recommendations'] ?? array() ),
		); }



	/** Keep AI recommendations compatible with the analyzed post type.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $result Parsed AI result.
	 * @return array
	 */
	private function filter_result_for_post_type( $post, $result ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		$taxonomies = is_array( $taxonomies ) ? $taxonomies : array();

		if ( ! in_array( 'category', $taxonomies, true ) ) {
			$result['recommended_categories'] = array();
		}

		if ( ! in_array( 'post_tag', $taxonomies, true ) ) {
			$result['recommended_tags'] = array();
		}

		$result['recommended_custom_taxonomies'] = array_values(
			array_filter(
				(array) ( $result['recommended_custom_taxonomies'] ?? array() ),
				static function ( $item ) use ( $post, $taxonomies ) {
					$taxonomy = isset( $item['taxonomy'] ) ? sanitize_key( $item['taxonomy'] ) : '';
					return '' !== $taxonomy && in_array( $taxonomy, $taxonomies, true ) && taxonomy_exists( $taxonomy ) && is_object_in_taxonomy( $post->post_type, $taxonomy );
				}
			)
		);

		return $result;
	}

	/** Store AI result in requested meta keys.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $result Parsed result.
	 * @param string $raw Raw JSON response.
	 */
	private function store_result( $post_id, $result, $raw ) {
		$now = current_time( 'mysql' );
		update_post_meta( $post_id, '_cto_ai_enabled', 1 );
		update_post_meta( $post_id, '_cto_ai_analyzed_at', $now );
		update_post_meta( $post_id, '_cto_ai_status', 'analyzed' );
		delete_post_meta( $post_id, '_cto_ai_error' );
		update_post_meta( $post_id, '_cto_ai_main_topic', $result['main_topic'] );
		update_post_meta( $post_id, '_cto_ai_content_cluster', $result['content_cluster'] );
		update_post_meta( $post_id, '_cto_ai_search_intent', $result['search_intent'] );
		update_post_meta( $post_id, '_cto_ai_target_audience', $result['target_audience'] );
		update_post_meta( $post_id, '_cto_ai_recommended_categories', $result['recommended_categories'] );
		update_post_meta( $post_id, '_cto_ai_recommended_tags', $result['recommended_tags'] );
		update_post_meta( $post_id, '_cto_ai_recommended_custom_taxonomies', $result['recommended_custom_taxonomies'] );
		update_post_meta( $post_id, '_cto_ai_internal_link_suggestions', $result['internal_link_suggestions'] );
		update_post_meta( $post_id, '_cto_ai_tone_assessment', $result['tone_assessment'] );
		update_post_meta( $post_id, '_cto_ai_summary', $result['summary'] );
		update_post_meta( $post_id, '_cto_ai_recommendations', $result['recommendations'] );
		update_post_meta( $post_id, '_cto_ai_raw_response', wp_kses_post( $raw ) );
		update_post_meta( $post_id, '_cto_ai_analysis_data', array_merge( $result, array( 'analyzed_at' => $now, 'automation_performed' => false ) ) );
		$this->initialize_recommendation_statuses( $post_id, $result );
	}

	/** Store AI error. */
	private function store_error( $post_id, $message ) {
		if ( $post_id ) { update_post_meta( $post_id, '_cto_ai_status', 'error' ); update_post_meta( $post_id, '_cto_ai_error', sanitize_text_field( $message ) ); }
		self::log( 'ai_analysis_failed', $post_id, sanitize_text_field( $message ) );
	}

	/** Initialize status map. */
	private function initialize_recommendation_statuses( $post_id, $result ) {
		$statuses = get_post_meta( $post_id, '_cto_ai_recommendation_status', true );
		$statuses = is_array( $statuses ) ? $statuses : array();
		foreach ( self::get_recommendation_keys( $result ) as $key ) { if ( empty( $statuses[ $key ] ) ) { $statuses[ $key ] = 'open'; } }
		update_post_meta( $post_id, '_cto_ai_recommendation_status', $statuses );
	}

	/** Get recommendation keys. */
	public static function get_recommendation_keys( $result ) {
		$keys = array();
		foreach ( array( 'recommended_categories', 'recommended_tags', 'internal_link_suggestions', 'recommendations' ) as $group ) { foreach ( (array) ( $result[ $group ] ?? array() ) as $index => $item ) { $keys[] = self::recommendation_key( $group, $index, $item ); } }
		foreach ( (array) ( $result['recommended_custom_taxonomies'] ?? array() ) as $tax_index => $tax_item ) { foreach ( (array) ( $tax_item['terms'] ?? array() ) as $term_index => $term_item ) { $keys[] = self::recommendation_key( 'recommended_custom_taxonomies', $tax_index . '_' . $term_index, $term_item ); } }
		return $keys;
	}

	/** Build stable recommendation key. */
	public static function recommendation_key( $group, $index, $item ) { return md5( $group . '|' . $index . '|' . wp_json_encode( $item ) ); }

	/** Sanitize choice. */
	private function sanitize_choice( $value, $allowed ) { $value = sanitize_key( $value ); return in_array( $value, $allowed, true ) ? $value : 'unknown'; }
	/** Sanitize recommendation items. */
	private function sanitize_recommendation_items( $items ) { $out = array(); foreach ( (array) $items as $item ) { if ( is_array( $item ) ) { $out[] = array( 'name' => sanitize_text_field( $item['name'] ?? '' ), 'reason' => sanitize_textarea_field( $item['reason'] ?? '' ), 'confidence' => max( 0, min( 1, (float) ( $item['confidence'] ?? 0 ) ) ) ); } elseif ( is_string( $item ) ) { $out[] = array( 'name' => sanitize_text_field( $item ), 'reason' => '', 'confidence' => 0 ); } } return array_values( array_filter( $out, static function ( $i ) { return '' !== $i['name']; } ) ); }
	/** Sanitize custom taxonomy items. */
	private function sanitize_custom_taxonomy_items( $items ) { $out = array(); foreach ( (array) $items as $item ) { if ( ! is_array( $item ) ) { continue; } $tax = sanitize_key( $item['taxonomy'] ?? '' ); if ( '' === $tax || ! taxonomy_exists( $tax ) ) { continue; } $out[] = array( 'taxonomy' => $tax, 'terms' => $this->sanitize_recommendation_items( $item['terms'] ?? array() ) ); } return $out; }
	/** Sanitize links. */
	private function sanitize_link_items( $items ) { $out = array(); foreach ( (array) $items as $item ) { if ( ! is_array( $item ) ) { continue; } $post_id = absint( $item['post_id'] ?? 0 ); if ( ! $post_id || ! get_post( $post_id ) ) { continue; } $out[] = array( 'post_id' => $post_id, 'title' => sanitize_text_field( $item['title'] ?? get_the_title( $post_id ) ), 'url' => esc_url_raw( get_permalink( $post_id ) ), 'reason' => sanitize_textarea_field( $item['reason'] ?? '' ), 'confidence' => max( 0, min( 1, (float) ( $item['confidence'] ?? 0 ) ) ) ); } return $out; }
	/** Sanitize tone. */
	private function sanitize_tone( $tone ) { $tone = is_array( $tone ) ? $tone : array(); return array( 'summary' => sanitize_textarea_field( $tone['summary'] ?? '' ), 'strengths' => array_map( 'sanitize_text_field', (array) ( $tone['strengths'] ?? array() ) ), 'risks' => array_map( 'sanitize_text_field', (array) ( $tone['risks'] ?? array() ) ) ); }
	/** Sanitize general recommendations. */
	private function sanitize_general_recommendations( $items ) { $out = array(); foreach ( (array) $items as $item ) { if ( ! is_array( $item ) ) { continue; } $out[] = array( 'type' => $this->sanitize_choice( $item['type'] ?? 'content', array( 'taxonomy', 'structure', 'content', 'linking', 'tone', 'unknown' ) ), 'priority' => $this->sanitize_choice( $item['priority'] ?? 'medium', array( 'low', 'medium', 'high', 'unknown' ) ), 'recommendation' => sanitize_textarea_field( $item['recommendation'] ?? '' ), 'reason' => sanitize_textarea_field( $item['reason'] ?? '' ) ); } return $out; }

	/** Get assigned terms grouped by taxonomy. */
	private function get_existing_terms( $post ) { $existing = array(); foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $taxonomy_object ) { $terms = get_the_terms( $post, $taxonomy ); $existing[ $taxonomy ] = array( 'label' => $taxonomy_object->labels->name, 'terms' => is_wp_error( $terms ) || empty( $terms ) ? array() : wp_list_pluck( $terms, 'name' ) ); } return $existing; }

	/** Get registered taxonomy context for prompts. */
	private function get_registered_taxonomies_context( $post_type ) {
		$context = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy => $taxonomy_object ) {
			$context[] = array(
				'slug'       => $taxonomy,
				'label'      => $taxonomy_object->labels->name,
				'is_builtin' => ! empty( $taxonomy_object->_builtin ),
			);
		}
		return $context;
	}

	/** Get available term names. */
	private function get_available_terms( $post_type ) { $available = array(); foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy => $taxonomy_object ) { $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 50, 'fields' => 'names' ) ); if ( ! is_wp_error( $terms ) ) { $available[ $taxonomy ] = array( 'label' => $taxonomy_object->labels->name, 'terms' => $terms ); } } return $available; }
	/** Get internal link candidates. */
	private function get_internal_link_candidates( $post ) { $taxonomies = get_object_taxonomies( $post->post_type ); $term_ids = ! empty( $taxonomies ) ? wp_get_object_terms( $post->ID, $taxonomies, array( 'fields' => 'ids' ) ) : array(); $args = array( 'post_type' => CTO_Utils::supported_post_types(), 'post_status' => 'publish', 'posts_per_page' => 20, 'post__not_in' => array( $post->ID ), 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true ); if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) && ! empty( $taxonomies ) ) { $args['tax_query'] = array( 'relation' => 'OR' ); foreach ( $taxonomies as $taxonomy ) { $args['tax_query'][] = array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $term_ids, 'operator' => 'IN' ); } } $q = new WP_Query( $args ); if ( empty( $q->posts ) && isset( $args['tax_query'] ) ) { unset( $args['tax_query'] ); $q = new WP_Query( $args ); } $items = array(); foreach ( $q->posts as $candidate ) { $items[] = array( 'post_id' => $candidate->ID, 'title' => get_the_title( $candidate ), 'url' => get_permalink( $candidate ), 'excerpt' => wp_trim_words( wp_strip_all_tags( $candidate->post_excerpt ? $candidate->post_excerpt : $candidate->post_content ), 24 ) ); } return $items; }
	/** Log important actions without secrets. */
	public static function log( $event, $post_id = 0, $message = '' ) { $log = get_option( self::LOG_OPTION, array() ); $log = is_array( $log ) ? $log : array(); array_unshift( $log, array( 'time' => current_time( 'mysql' ), 'event' => sanitize_key( $event ), 'post_id' => absint( $post_id ), 'message' => sanitize_text_field( $message ), 'user_id' => get_current_user_id() ) ); update_option( self::LOG_OPTION, array_slice( $log, 0, 100 ), false ); }
}
