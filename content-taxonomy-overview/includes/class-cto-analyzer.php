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

	/**
	 * Get content structure data.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function get_content_data( $post ) {
		$content        = (string) $post->post_content;
		$word_count     = str_word_count( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		$h2_count       = preg_match_all( '/<h2\b[^>]*>/i', $content );
		$links          = $this->count_links( $content );
		$meta           = $this->get_seo_meta_description( $post->ID );
		$featured_image = has_post_thumbnail( $post->ID );

		return array(
			'word_count'               => (int) $word_count,
			'h2_count'                 => (int) $h2_count,
			'internal_links'            => (int) $links['internal'],
			'external_links'            => (int) $links['external'],
			'featured_image'            => (bool) $featured_image,
			'seo_plugin_detected'       => (bool) $meta['plugin_detected'],
			'meta_description_present'  => (bool) $meta['description_present'],
		);
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
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal  = 0;
		$external  = 0;

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$href = trim( html_entity_decode( $href ) );
				if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
					continue;
				}

				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( empty( $host ) || $home_host === $host ) {
					$internal++;
				} else {
					$external++;
				}
			}
		}

		return array( 'internal' => $internal, 'external' => $external );
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
