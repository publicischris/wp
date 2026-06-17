<?php
/**
 * Rule-based analyzer.
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs read-only post/page analysis and stores results in post meta.
 */
class CTO_Analyzer {
	/**
	 * Analyze a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function analyze_post( $post_id ) {
		$post_id = absint( $post_id );
		clean_post_cache( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, CTO_Utils::supported_post_types(), true ) || ! in_array( $post->post_status, CTO_Utils::supported_statuses(), true ) ) {
			return new WP_Error( 'cto_invalid_post', __( 'Unsupported post for analysis.', 'content-taxonomy-overview' ) );
		}

		$taxonomy_data = $this->get_taxonomy_data( $post );
		$content_data  = $this->get_content_data( $post );

		$taxonomy_score  = $this->calculate_taxonomy_score( $taxonomy_data );
		$structure_score = $this->calculate_structure_score( $content_data );
		$total_score     = (int) round( ( $taxonomy_score + $structure_score ) / 2 );
		$status          = CTO_Utils::status_from_score( $total_score );
		$analysis_data   = array(
			'post_id'              => $post_id,
			'post_type'            => $post->post_type,
			'post_status'          => $post->post_status,
			'taxonomies'           => $taxonomy_data,
			'content'              => $content_data,
			'content_extraction'   => isset( $content_data['content_extraction'] ) ? $content_data['content_extraction'] : array(),
			'scoring'              => array(
				'taxonomy'  => $this->build_taxonomy_criteria( $taxonomy_data ),
				'structure' => $this->build_structure_criteria( $content_data ),
				'total'     => array(
					'calculation' => 'average_taxonomy_structure',
					'score'       => $total_score,
					'max_points'  => 100,
					'status'      => $status,
				),
			),
			'phase_2_ai_available' => CTO_AI_Service::is_configured(),
		);

		update_post_meta( $post_id, '_cto_taxonomy_score', $taxonomy_score );
		update_post_meta( $post_id, '_cto_structure_score', $structure_score );
		update_post_meta( $post_id, '_cto_total_score', $total_score );
		update_post_meta( $post_id, '_cto_analysis_status', $status );
		update_post_meta( $post_id, '_cto_analysis_data', $analysis_data );
		update_post_meta( $post_id, '_cto_analyzed_at', current_time( 'mysql' ) );

		return $analysis_data;
	}

	/**
	 * Analyze all supported content.
	 *
	 * @return int Number analyzed.
	 */
	public function analyze_all() {
		$query = new WP_Query(
			array(
				'post_type'              => CTO_Utils::supported_post_types(),
				'post_status'            => CTO_Utils::supported_statuses(),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$count = 0;
		foreach ( $query->posts as $post_id ) {
			if ( ! is_wp_error( $this->analyze_post( $post_id ) ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Get taxonomy assignment data.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function get_taxonomy_data( $post ) {
		$taxonomies  = get_object_taxonomies( $post->post_type, 'objects' );
		$assignments = array();
		$total_terms = 0;
		$has_custom  = false;

		foreach ( $taxonomies as $taxonomy => $taxonomy_object ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$terms = array();
			}

			$term_names = wp_list_pluck( $terms, 'name' );
			$total_terms += count( $terms );
			if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) && ! empty( $terms ) ) {
				$has_custom = true;
			}

			$assignments[ $taxonomy ] = array(
				'label'     => $taxonomy_object->labels->name,
				'is_custom' => ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ),
				'terms'     => $term_names,
			);
		}

		$category_terms = isset( $assignments['category']['terms'] ) ? $assignments['category']['terms'] : array();
		$tag_terms      = isset( $assignments['post_tag']['terms'] ) ? $assignments['post_tag']['terms'] : array();

		return array(
			'category_taxonomy_exists' => isset( $assignments['category'] ),
			'tag_taxonomy_exists'      => isset( $assignments['post_tag'] ),
			'assignments'              => $assignments,
			'category_terms'           => $category_terms,
			'tag_terms'                => $tag_terms,
			'total_terms'              => $total_terms,
			'has_category'             => ! empty( $category_terms ),
			'has_tag'                  => ! empty( $tag_terms ),
			'has_custom_taxonomy_term' => $has_custom,
			'custom_taxonomies_exist'  => $this->custom_taxonomies_exist_for_post_type( $post->post_type ),
			'has_default_category'     => $this->has_default_category_name( $category_terms ),
		);
	}

	/**
	 * Calculate taxonomy score.
	 *
	 * @param array $data Taxonomy data.
	 * @return int
	 */
	private function calculate_taxonomy_score( $data ) {
		$score = 0;
		$score += empty( $data['category_taxonomy_exists'] ) || ! empty( $data['has_category'] ) ? 30 : 0;
		$score += empty( $data['tag_taxonomy_exists'] ) || ! empty( $data['has_tag'] ) ? 20 : 0;
		$score += ( (int) $data['total_terms'] > 1 ) ? 20 : 0;
		$score += empty( $data['category_taxonomy_exists'] ) || empty( $data['has_default_category'] ) ? 20 : 0;
		$score += ( ! empty( $data['custom_taxonomies_exist'] ) && ! empty( $data['has_custom_taxonomy_term'] ) ) ? 10 : 0;

		return min( 100, $score );
	}



	/** Build transparent taxonomy criteria details without changing scoring logic.
	 *
	 * @param array $data Taxonomy data.
	 * @return array
	 */
	private function build_taxonomy_criteria( $data ) {
		$category_relevant = ! empty( $data['category_taxonomy_exists'] );
		$tag_relevant      = ! empty( $data['tag_taxonomy_exists'] );
		$custom_relevant   = ! empty( $data['custom_taxonomies_exist'] );

		return array(
			'score'      => $this->calculate_taxonomy_score( $data ),
			'max_points' => 100,
			'criteria'   => array(
				'category_present'       => array( 'label' => __( 'Kategorie vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $data['has_category'] ), 'relevant' => $category_relevant, 'points' => ( ! $category_relevant || ! empty( $data['has_category'] ) ) ? 30 : 0, 'max_points' => 30 ),
				'tag_present'            => array( 'label' => __( 'Tag vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $data['has_tag'] ), 'relevant' => $tag_relevant, 'points' => ( ! $tag_relevant || ! empty( $data['has_tag'] ) ) ? 20 : 0, 'max_points' => 20 ),
				'multiple_assignments'    => array( 'label' => __( 'Mehr als eine Taxonomie-Zuordnung', 'content-taxonomy-overview' ), 'met' => (int) $data['total_terms'] > 1, 'relevant' => true, 'points' => ( (int) $data['total_terms'] > 1 ) ? 20 : 0, 'max_points' => 20 ),
				'no_default_category'     => array( 'label' => __( 'Keine Uncategorized/Allgemein-Kategorie', 'content-taxonomy-overview' ), 'met' => empty( $data['has_default_category'] ), 'relevant' => $category_relevant, 'points' => ( ! $category_relevant || empty( $data['has_default_category'] ) ) ? 20 : 0, 'max_points' => 20 ),
				'custom_taxonomy_present' => array( 'label' => __( 'Custom Taxonomy vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $data['has_custom_taxonomy_term'] ), 'relevant' => $custom_relevant, 'points' => ( $custom_relevant && ! empty( $data['has_custom_taxonomy_term'] ) ) ? 10 : 0, 'max_points' => 10 ),
			),
		);
	}

	/** Build transparent structure criteria details without changing scoring logic.
	 *
	 * @param array $data Content structure data.
	 * @return array
	 */
	private function build_structure_criteria( $data ) {
		$meta_relevant = ! empty( $data['seo_plugin_detected'] );

		return array(
			'score'      => $this->calculate_structure_score( $data ),
			'max_points' => 100,
			'criteria'   => array(
				'word_count_over_500'     => array( 'label' => __( 'Mehr als 500 Wörter', 'content-taxonomy-overview' ), 'met' => (int) $data['word_count'] > 500, 'relevant' => true, 'points' => ( (int) $data['word_count'] > 500 ) ? 25 : 0, 'max_points' => 25 ),
				'h2_present'              => array( 'label' => __( 'H2 vorhanden', 'content-taxonomy-overview' ), 'met' => (int) $data['h2_count'] > 0, 'relevant' => true, 'points' => ( (int) $data['h2_count'] > 0 ) ? 20 : 0, 'max_points' => 20 ),
				'featured_image_present'  => array( 'label' => __( 'Featured Image vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $data['featured_image'] ), 'relevant' => true, 'points' => ! empty( $data['featured_image'] ) ? 20 : 0, 'max_points' => 20 ),
				'internal_link_present'   => array( 'label' => __( 'Interner Link vorhanden', 'content-taxonomy-overview' ), 'met' => (int) $data['internal_links'] > 0, 'relevant' => true, 'points' => ( (int) $data['internal_links'] > 0 ) ? 20 : 0, 'max_points' => 20 ),
				'meta_description_present'=> array( 'label' => __( 'Meta Description vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $data['meta_description_present'] ), 'relevant' => $meta_relevant, 'points' => ( $meta_relevant && ! empty( $data['meta_description_present'] ) ) ? 15 : 0, 'max_points' => 15 ),
			),
		);
	}

	/**
	 * Get content structure data.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function get_content_data( $post ) {
		$extracted      = $this->extract_analyzable_content( $post->ID );
		$links          = $this->count_links( $extracted['link_source'] );
		$meta           = $this->get_seo_meta_description( $post->ID );
		$featured_image = has_post_thumbnail( $post->ID );

		$extracted['diagnostics']['links_detected']          = (int) $links['total'];
		$extracted['diagnostics']['internal_links_detected'] = (int) $links['internal'];
		$extracted['diagnostics']['external_links_detected'] = (int) $links['external'];

		return array(
			'word_count'               => (int) $extracted['word_count'],
			'h2_count'                 => (int) $extracted['h2_count'],
			'internal_links'            => (int) $links['internal'],
			'external_links'            => (int) $links['external'],
			'featured_image'            => (bool) $featured_image,
			'seo_plugin_detected'       => (bool) $meta['plugin_detected'],
			'meta_description_present'  => (bool) $meta['description_present'],
			'content_extraction'        => $extracted['diagnostics'],
		);
	}

	/**
	 * Extract analyzable content from post_content while preserving visible builder text.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function extract_analyzable_content( $post_id ) {
		$post        = get_post( $post_id );
		$raw         = $post ? (string) $post->post_content : '';
		$diagnostics = array(
			'source'                     => 'post_content',
			'extraction_strategy_used'   => 'shortcode_tag_cleanup',
			'fusion_shortcodes_detected' => (bool) preg_match( '/\[\/?fusion_[a-z0-9_:-]+\b/i', $raw ),
			'raw_length'                 => (int) ( function_exists( 'mb_strlen' ) ? mb_strlen( $raw ) : strlen( $raw ) ),
			'raw_content_length'         => (int) ( function_exists( 'mb_strlen' ) ? mb_strlen( $raw ) : strlen( $raw ) ),
			'clean_text_length'          => 0,
			'cleaned_text_length'        => 0,
			'cleaned_text_word_count'    => 0,
			'analyzable_text_detected'   => false,
			'word_count_method'          => 'unicode_regex',
			'shortcode_cleanup_error'    => false,
			'preg_last_error_code'       => 0,
			'rendered_fallback_used'     => false,
			'rendered_fallback_word_count'=> 0,
			'builder_meta_fallback_used' => false,
			'builder_meta_fields_checked'=> array(),
			'links_detected'             => 0,
			'internal_links_detected'    => 0,
			'external_links_detected'    => 0,
			'text_sample'                => '',
		);

		$clean = $this->extract_visible_text_from_builder_content( $raw, $diagnostics );
		$words = $this->count_unicode_words( $clean );

		if ( 0 === $words && '' !== trim( $raw ) ) {
			$rendered = $this->get_rendered_content_fallback( $raw );
			if ( '' !== $rendered ) {
				$rendered_clean = $this->extract_visible_text_from_builder_content( $rendered, $diagnostics );
				$rendered_words = $this->count_unicode_words( $rendered_clean );
				$diagnostics['rendered_fallback_used']       = true;
				$diagnostics['rendered_fallback_word_count'] = $rendered_words;
				if ( $rendered_words > $words ) {
					$clean = $rendered_clean;
					$words = $rendered_words;
					$diagnostics['extraction_strategy_used'] = 'rendered_content_fallback';
				}
			}
		}

		if ( 0 === $words ) {
			$meta = $this->get_builder_meta_fallback_content( $post_id, $diagnostics );
			if ( '' !== $meta ) {
				$meta_clean = $this->extract_visible_text_from_builder_content( $meta, $diagnostics );
				$meta_words = $this->count_unicode_words( $meta_clean );
				if ( $meta_words > 0 ) {
					$clean = $meta_clean;
					$words = $meta_words;
					$diagnostics['builder_meta_fallback_used'] = true;
					$diagnostics['extraction_strategy_used']   = 'builder_meta_fallback';
				}
			}
		}

		$txt_len = function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean );
		$diagnostics['clean_text_length']        = (int) $txt_len;
		$diagnostics['cleaned_text_length']      = (int) $txt_len;
		$diagnostics['cleaned_text_word_count']  = (int) $words;
		$diagnostics['analyzable_text_detected'] = $words > 0;
		$diagnostics['text_sample']              = function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, 250 ) : substr( $clean, 0, 250 );

		return array(
			'text'        => $clean,
			'link_source' => $raw,
			'word_count'  => $words,
			'h2_count'    => $this->count_h2_headings( $raw ),
			'diagnostics' => $diagnostics,
		);
	}

	/** Extract visible text from raw/builder content. */
	private function extract_visible_text_from_builder_content( $content, &$diagnostics = null ) {
		$text = $this->shortcode_content_to_text( (string) $content, $diagnostics );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
		$text = preg_replace( '~https?://\S+|www\.\S+~iu', ' ', $text );
		$text = preg_replace( '/\b\S+\.(?:jpg|jpeg|png|gif|webp|svg|css|js|pdf)\b/iu', ' ', (string) $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	/** Remove shortcode wrappers and attributes while keeping enclosed visible text. */
	private function shortcode_content_to_text( $content, &$diagnostics = null ) {
		$content = (string) $content;
		$result  = preg_replace( '/\[fusion_(?:separator|gallery|imageframe|builder_next_page)\b[^\]]*\]/i', ' ', $content );
		$error   = preg_last_error();
		if ( null === $result ) {
			if ( is_array( $diagnostics ) ) { $diagnostics['shortcode_cleanup_error'] = true; $diagnostics['preg_last_error_code'] = $error; }
			$result = $content;
		}
		$result2 = preg_replace( '/\[\/?[a-zA-Z0-9_:-]+(?:\s+(?:"[^"]*"|\'[^\']*\'|[^\]])*)?\]/', ' ', $result );
		$error2  = preg_last_error();
		if ( null === $result2 ) {
			if ( is_array( $diagnostics ) ) { $diagnostics['shortcode_cleanup_error'] = true; $diagnostics['preg_last_error_code'] = $error2; }
			return $result;
		}
		if ( is_array( $diagnostics ) ) { $diagnostics['preg_last_error_code'] = max( (int) ( $diagnostics['preg_last_error_code'] ?? 0 ), $error, $error2 ); }
		return (string) $result2;
	}

	/** Render content as fallback when shortcode text cleanup finds no words. */
	private function get_rendered_content_fallback( $raw ) {
		if ( '' === trim( (string) $raw ) ) { return ''; }
		if ( function_exists( 'do_shortcode' ) ) {
			$rendered = do_shortcode( $raw );
			if ( is_string( $rendered ) && trim( $rendered ) !== trim( $raw ) ) { return $rendered; }
		}
		if ( has_filter( 'the_content' ) ) {
			$filtered = apply_filters( 'the_content', $raw );
			if ( is_string( $filtered ) ) { return $filtered; }
		}
		return '';
	}

	/** Get selected builder meta fallback content without scanning unrelated large settings. */
	private function get_builder_meta_fallback_content( $post_id, &$diagnostics ) {
		$keys = array( '_fusion_builder_content', 'fusion_builder_content', '_avada_builder_content', 'avada_builder_content' );
		$out  = '';
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			$diagnostics['builder_meta_fields_checked'][] = $key;
			if ( is_string( $value ) && strlen( $value ) < 200000 && preg_match( '/\w{3,}/u', $value ) ) { $out .= ' ' . $value; }
		}
		return trim( $out );
	}

	/** Count Unicode words in cleaned visible text. */
	private function count_unicode_words( $text ) {
		if ( ! preg_match_all( "/(?=[\p{L}\p{N}'’\-]*\p{L})[\p{L}\p{N}]+(?:[\-’'][\p{L}\p{N}]+)*/u", (string) $text, $matches ) ) {
			return 0;
		}
		return count( $matches[0] );
	}

	/** Count real H2 tags and Fusion title shortcodes configured as H2. */
	private function count_h2_headings( $content ) {
		$count = preg_match_all( '/<h2\b[^>]*>/i', (string) $content );
		if ( preg_match_all( '/\[fusion_title\b([^\]]*)\](.*?)\[\/fusion_title\]/is', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $attributes ) {
				if ( preg_match( '/\b(?:size|title_size|heading_size)=(["\']?)(h?2)\1/i', $attributes ) ) {
					$count++;
				}
			}
		}
		return (int) $count;
	}

	/**
	 * Calculate structure score.
	 *
	 * @param array $data Content data.
	 * @return int
	 */
	private function calculate_structure_score( $data ) {
		$score = 0;
		$score += ( (int) $data['word_count'] > 500 ) ? 25 : 0;
		$score += ( (int) $data['h2_count'] > 0 ) ? 20 : 0;
		$score += ! empty( $data['featured_image'] ) ? 20 : 0;
		$score += ( (int) $data['internal_links'] > 0 ) ? 20 : 0;
		$score += ( ! empty( $data['seo_plugin_detected'] ) && ! empty( $data['meta_description_present'] ) ) ? 15 : 0;

		return min( 100, $score );
	}

	/**
	 * Count internal and external links.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	private function count_links( $content ) {
		$home_host = $this->normalize_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$internal  = 0;
		$external  = 0;
		$urls      = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', (string) $content, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		if ( preg_match_all( '/\[[^\]]+\]/', (string) $content, $shortcode_matches ) ) {
			foreach ( $shortcode_matches[0] as $shortcode_tag ) {
				if ( preg_match_all( '/\b(?:href|link|url)=(["\'])(.*?)\1/i', $shortcode_tag, $matches ) ) {
					$urls = array_merge( $urls, $matches[2] );
				}
			}
		}

		foreach ( $urls as $href ) {
			$href = trim( html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
				continue;
			}

			$host = $this->normalize_host( wp_parse_url( $href, PHP_URL_HOST ) );
			if ( empty( $host ) || $home_host === $host ) {
				$internal++;
			} else {
				$external++;
			}
		}

		return array( 'internal' => $internal, 'external' => $external, 'total' => $internal + $external );
	}

	/** Normalize host names for internal/external link comparison. */
	private function normalize_host( $host ) {
		$host = strtolower( (string) $host );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Check SEO meta descriptions for Yoast SEO or Rank Math.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_seo_meta_description( $post_id ) {
		$yoast     = defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
		$rank_math = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
		$desc      = '';

		if ( $yoast ) {
			$desc = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		}

		if ( '' === $desc && $rank_math ) {
			$desc = (string) get_post_meta( $post_id, 'rank_math_description', true );
		}

		return array(
			'plugin_detected'      => $yoast || $rank_math,
			'description_present' => '' !== trim( $desc ),
		);
	}

	/**
	 * Determine if custom taxonomies exist for post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function custom_taxonomies_exist_for_post_type( $post_type ) {
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check for default category names.
	 *
	 * @param string[] $terms Category names.
	 * @return bool
	 */
	private function has_default_category_name( $terms ) {
		$default_names = array( 'uncategorized', 'allgemein' );
		foreach ( $terms as $term ) {
			if ( in_array( strtolower( $term ), $default_names, true ) ) {
				return true;
			}
		}

		return false;
	}
}
