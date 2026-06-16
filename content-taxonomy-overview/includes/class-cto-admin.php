<?php
/**
 * Admin overview page.
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for overview and actions.
 */
class CTO_Admin {
	/** @var CTO_Analyzer */
	private $analyzer;

	/**
	 * Constructor.
	 *
	 * @param CTO_Analyzer $analyzer Analyzer.
	 */
	public function __construct( $analyzer ) {
		$this->analyzer = $analyzer;
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_cto_analyze_all', array( $this, 'handle_analyze_all' ) );
		add_action( 'admin_post_cto_analyze_single', array( $this, 'handle_analyze_single' ) );
	}

	/** Register menu. */
	public function register_menu() {
		add_menu_page( __( 'Content Taxonomy', 'content-taxonomy-overview' ), __( 'Content Taxonomy', 'content-taxonomy-overview' ), 'manage_options', 'content-taxonomy-overview', array( $this, 'render_page' ), 'dashicons-category', 58 );
	}

	/** Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'content-taxonomy-overview' ) ) {
			return;
		}
		wp_enqueue_style( 'cto-admin', CTO_PLUGIN_URL . 'assets/admin.css', array(), CTO_VERSION );
		wp_enqueue_script( 'cto-admin', CTO_PLUGIN_URL . 'assets/admin.js', array(), CTO_VERSION, true );
	}

	/** Render overview page. */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'content-taxonomy-overview' ) );
		}

		$filters = $this->get_filters();
		$posts   = $this->get_posts( $filters );
		?>
		<div class="wrap cto-wrap">
			<h1><?php esc_html_e( 'Content Taxonomy Overview', 'content-taxonomy-overview' ); ?></h1>
			<?php $this->render_notices(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cto-actions">
				<?php wp_nonce_field( 'cto_analyze_all' ); ?>
				<input type="hidden" name="action" value="cto_analyze_all" />
				<?php submit_button( __( 'Alle Inhalte neu analysieren', 'content-taxonomy-overview' ), 'primary', 'submit', false ); ?>
			</form>
			<form method="get" class="cto-filters">
				<input type="hidden" name="page" value="content-taxonomy-overview" />
				<?php $this->render_filters( $filters ); ?>
			</form>
			<?php $this->render_table( $posts ); ?>
		</div>
		<?php
	}

	/** Handle analyze all. */
	public function handle_analyze_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}
		check_admin_referer( 'cto_analyze_all' );
		$count = $this->analyzer->analyze_all();
		wp_safe_redirect( add_query_arg( array( 'page' => 'content-taxonomy-overview', 'cto_notice' => 'analyzed_all', 'cto_count' => $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Handle single analyze. */
	public function handle_analyze_single() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}
		check_admin_referer( 'cto_analyze_single_' . $post_id );
		$this->analyzer->analyze_post( $post_id );
		wp_safe_redirect( add_query_arg( array( 'page' => 'content-taxonomy-overview', 'cto_notice' => 'analyzed_single' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Render notices. */
	private function render_notices() {
		$notice = isset( $_GET['cto_notice'] ) ? sanitize_key( wp_unslash( $_GET['cto_notice'] ) ) : '';
		if ( 'analyzed_all' === $notice ) {
			$count = isset( $_GET['cto_count'] ) ? absint( $_GET['cto_count'] ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Inhalte wurden analysiert.', 'content-taxonomy-overview' ), $count ) ) . '</p></div>';
		} elseif ( 'analyzed_single' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Der Inhalt wurde neu analysiert.', 'content-taxonomy-overview' ) . '</p></div>';
		}
	}

	/** Get filters.
	 *
	 * @return array
	 */
	private function get_filters() {
		return array(
			'post_type' => isset( $_GET['cto_post_type'] ) ? sanitize_key( wp_unslash( $_GET['cto_post_type'] ) ) : '',
			'status'    => isset( $_GET['cto_status'] ) ? sanitize_key( wp_unslash( $_GET['cto_status'] ) ) : '',
			'rating'    => isset( $_GET['cto_rating'] ) ? sanitize_text_field( wp_unslash( $_GET['cto_rating'] ) ) : '',
			's'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date',
			'order'     => isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC',
		);
	}

	/** Query posts.
	 *
	 * @param array $filters Filters.
	 * @return WP_Post[]
	 */
	private function get_posts( $filters ) {
		$args = array(
			'post_type'      => in_array( $filters['post_type'], CTO_Utils::supported_post_types(), true ) ? $filters['post_type'] : CTO_Utils::supported_post_types(),
			'post_status'    => in_array( $filters['status'], CTO_Utils::supported_statuses(), true ) ? $filters['status'] : CTO_Utils::supported_statuses(),
			'posts_per_page' => 100,
			's'              => $filters['s'],
		);

		if ( 'score' === $filters['orderby'] ) {
			$args['meta_key'] = '_cto_total_score';
			$args['orderby']  = 'meta_value_num';
		} elseif ( 'status' === $filters['orderby'] ) {
			$args['meta_key'] = '_cto_analysis_status';
			$args['orderby']  = 'meta_value';
		} elseif ( 'post_type' === $filters['orderby'] ) {
			$args['orderby'] = 'type';
		} else {
			$args['orderby'] = 'modified';
		}
		$args['order'] = $filters['order'];

		if ( in_array( $filters['rating'], array( 'OK', 'Prüfen', 'Unvollständig' ), true ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_cto_analysis_status',
					'value' => $filters['rating'],
				),
			);
		}

		return get_posts( $args );
	}

	/** Render filters.
	 *
	 * @param array $filters Filters.
	 */
	private function render_filters( $filters ) {
		?>
		<select name="cto_post_type"><option value=""><?php esc_html_e( 'Alle Post Types', 'content-taxonomy-overview' ); ?></option><option value="post" <?php selected( $filters['post_type'], 'post' ); ?>>Posts</option><option value="page" <?php selected( $filters['post_type'], 'page' ); ?>>Pages</option></select>
		<select name="cto_status"><option value=""><?php esc_html_e( 'Alle Status', 'content-taxonomy-overview' ); ?></option><option value="publish" <?php selected( $filters['status'], 'publish' ); ?>>Published</option><option value="draft" <?php selected( $filters['status'], 'draft' ); ?>>Draft</option></select>
		<select name="cto_rating"><option value=""><?php esc_html_e( 'Alle Bewertungen', 'content-taxonomy-overview' ); ?></option><option value="OK" <?php selected( $filters['rating'], 'OK' ); ?>>OK</option><option value="Prüfen" <?php selected( $filters['rating'], 'Prüfen' ); ?>>Prüfen</option><option value="Unvollständig" <?php selected( $filters['rating'], 'Unvollständig' ); ?>>Unvollständig</option></select>
		<input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Titel suchen', 'content-taxonomy-overview' ); ?>" />
		<select name="orderby"><option value="date" <?php selected( $filters['orderby'], 'date' ); ?>>Datum</option><option value="score" <?php selected( $filters['orderby'], 'score' ); ?>>Score</option><option value="status" <?php selected( $filters['orderby'], 'status' ); ?>>Status</option><option value="post_type" <?php selected( $filters['orderby'], 'post_type' ); ?>>Post Type</option></select>
		<select name="order"><option value="DESC" <?php selected( $filters['order'], 'DESC' ); ?>>DESC</option><option value="ASC" <?php selected( $filters['order'], 'ASC' ); ?>>ASC</option></select>
		<?php submit_button( __( 'Filtern', 'content-taxonomy-overview' ), 'secondary', 'submit', false ); ?>
		<?php
	}

	/** Render table.
	 *
	 * @param WP_Post[] $posts Posts.
	 */
	private function render_table( $posts ) {
		?>
		<table class="widefat fixed striped cto-table">
		<thead><tr><th>Titel</th><th>Post Type</th><th>Status</th><th>Datum</th><th>Kategorien</th><th>Tags</th><th>Custom Taxonomies</th><th>Wörter</th><th>Interne Links</th><th>Externe Links</th><th>H2</th><th>Featured Image</th><th>Taxonomie</th><th>Struktur</th><th>Gesamt</th><th>Hinweis</th><th>Aktion</th></tr></thead>
		<tbody>
		<?php if ( empty( $posts ) ) : ?>
			<tr><td colspan="17"><?php esc_html_e( 'Keine Inhalte gefunden.', 'content-taxonomy-overview' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $posts as $post ) : $this->render_row( $post ); endforeach; ?>
		</tbody></table>
		<?php
	}

	/** Render row.
	 *
	 * @param WP_Post $post Post.
	 */
	private function render_row( $post ) {
		$data = get_post_meta( $post->ID, '_cto_analysis_data', true );
		if ( ! is_array( $data ) ) {
			$data = array( 'taxonomies' => array(), 'content' => array() );
		}
		$tax     = isset( $data['taxonomies'] ) ? $data['taxonomies'] : array();
		$content = isset( $data['content'] ) ? $data['content'] : array();
		$assign  = isset( $tax['assignments'] ) ? $tax['assignments'] : array();
		$custom  = array();
		foreach ( $assign as $slug => $info ) {
			if ( ! empty( $info['is_custom'] ) && ! empty( $info['terms'] ) ) {
				$custom[] = $info['label'] . ': ' . implode( ', ', $info['terms'] );
			}
		}
		$action = wp_nonce_url( add_query_arg( array( 'action' => 'cto_analyze_single', 'post_id' => $post->ID ), admin_url( 'admin-post.php' ) ), 'cto_analyze_single_' . $post->ID );
		?>
		<tr>
			<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
			<td><?php echo esc_html( $post->post_type ); ?></td><td><?php echo esc_html( $post->post_status ); ?></td><td><?php echo esc_html( get_the_modified_date( '', $post ) ); ?></td>
			<td><?php echo esc_html( implode( ', ', isset( $tax['category_terms'] ) ? $tax['category_terms'] : array() ) ); ?></td><td><?php echo esc_html( implode( ', ', isset( $tax['tag_terms'] ) ? $tax['tag_terms'] : array() ) ); ?></td><td><?php echo esc_html( implode( ' | ', $custom ) ); ?></td>
			<td><?php echo esc_html( isset( $content['word_count'] ) ? $content['word_count'] : '—' ); ?></td><td><?php echo esc_html( isset( $content['internal_links'] ) ? $content['internal_links'] : '—' ); ?></td><td><?php echo esc_html( isset( $content['external_links'] ) ? $content['external_links'] : '—' ); ?></td><td><?php echo esc_html( isset( $content['h2_count'] ) ? $content['h2_count'] : '—' ); ?></td><td><?php echo ! empty( $content['featured_image'] ) ? esc_html__( 'Ja', 'content-taxonomy-overview' ) : esc_html__( 'Nein', 'content-taxonomy-overview' ); ?></td>
			<td><?php echo esc_html( get_post_meta( $post->ID, '_cto_taxonomy_score', true ) ); ?></td><td><?php echo esc_html( get_post_meta( $post->ID, '_cto_structure_score', true ) ); ?></td><td><strong><?php echo esc_html( get_post_meta( $post->ID, '_cto_total_score', true ) ); ?></strong></td><td><span class="cto-status cto-status-<?php echo esc_attr( sanitize_html_class( get_post_meta( $post->ID, '_cto_analysis_status', true ) ) ); ?>"><?php echo esc_html( get_post_meta( $post->ID, '_cto_analysis_status', true ) ); ?></span></td>
			<td><a class="button button-small" href="<?php echo esc_url( $action ); ?>"><?php esc_html_e( 'Neu analysieren', 'content-taxonomy-overview' ); ?></a></td>
		</tr>
		<?php
	}
}
