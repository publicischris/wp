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
		add_action( 'admin_post_cto_save_columns', array( $this, 'handle_save_columns' ) );
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
		$query   = $this->get_posts( $filters );
		$columns = $this->get_visible_columns();
		?>
		<div class="wrap cto-wrap">
			<h1><?php esc_html_e( 'Content Taxonomy Overview', 'content-taxonomy-overview' ); ?></h1>
			<?php $this->render_notices(); ?>
			<?php $this->render_column_options( $columns ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cto-actions">
				<?php wp_nonce_field( 'cto_analyze_all' ); ?>
				<input type="hidden" name="action" value="cto_analyze_all" />
				<?php submit_button( __( 'Alle Inhalte neu analysieren', 'content-taxonomy-overview' ), 'primary', 'submit', false ); ?>
			</form>
			<form method="get" class="cto-filters">
				<input type="hidden" name="page" value="content-taxonomy-overview" />
				<?php $this->render_filters( $filters ); ?>
			</form>
			<?php $this->render_table( $query->posts, $columns ); ?>
			<?php $this->render_pagination( $query, $filters ); ?>
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
		} elseif ( 'columns_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Spaltenauswahl gespeichert.', 'content-taxonomy-overview' ) . '</p></div>';
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
			'per_page'  => isset( $_GET['per_page'] ) && in_array( absint( $_GET['per_page'] ), array( 20, 50, 100 ), true ) ? absint( $_GET['per_page'] ) : 20,
			'paged'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
	}

	/** Query posts.
	 *
	 * @param array $filters Filters.
	 * @return WP_Query
	 */
	private function get_posts( $filters ) {
		$args = array(
			'post_type'      => in_array( $filters['post_type'], CTO_Utils::supported_post_types(), true ) ? $filters['post_type'] : CTO_Utils::supported_post_types(),
			'post_status'    => in_array( $filters['status'], CTO_Utils::supported_statuses(), true ) ? $filters['status'] : CTO_Utils::supported_statuses(),
			'posts_per_page' => $filters['per_page'],
			'paged'          => $filters['paged'],
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

		return new WP_Query( $args );
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
		<select name="per_page"><option value="20" <?php selected( $filters['per_page'], 20 ); ?>>20</option><option value="50" <?php selected( $filters['per_page'], 50 ); ?>>50</option><option value="100" <?php selected( $filters['per_page'], 100 ); ?>>100</option></select>
		<input type="hidden" name="paged" value="1" />
		<?php submit_button( __( 'Filtern', 'content-taxonomy-overview' ), 'secondary', 'submit', false ); ?>
		<?php
	}


	/** Get table columns.
	 *
	 * @return array
	 */
	private function get_columns() {
		return array(
			'title'        => __( 'Titel', 'content-taxonomy-overview' ),
			'post_type'    => __( 'Post Type', 'content-taxonomy-overview' ),
			'status'       => __( 'Status', 'content-taxonomy-overview' ),
			'date'         => __( 'Datum', 'content-taxonomy-overview' ),
			'categories'   => __( 'Kategorien', 'content-taxonomy-overview' ),
			'tags'         => __( 'Tags', 'content-taxonomy-overview' ),
			'custom_tax'   => __( 'Custom Taxonomies', 'content-taxonomy-overview' ),
			'words'        => __( 'Wörter', 'content-taxonomy-overview' ),
			'internal'     => __( 'Interne Links', 'content-taxonomy-overview' ),
			'external'     => __( 'Externe Links', 'content-taxonomy-overview' ),
			'h2'           => __( 'H2', 'content-taxonomy-overview' ),
			'featured'     => __( 'Featured Image', 'content-taxonomy-overview' ),
			'tax_score'    => __( 'Taxonomie', 'content-taxonomy-overview' ),
			'struct_score' => __( 'Struktur', 'content-taxonomy-overview' ),
			'total_score'  => __( 'Gesamt', 'content-taxonomy-overview' ),
			'notice'       => __( 'Hinweis', 'content-taxonomy-overview' ),
			'action'       => __( 'Aktion', 'content-taxonomy-overview' ),
		);
	}

	/** Get visible columns for current user.
	 *
	 * @return string[]
	 */
	private function get_visible_columns() {
		$columns = array_keys( $this->get_columns() );
		$saved   = get_user_meta( get_current_user_id(), 'cto_visible_columns', true );

		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return $columns;
		}

		$visible = array_values( array_intersect( $columns, array_map( 'sanitize_key', $saved ) ) );
		return empty( $visible ) ? $columns : $visible;
	}

	/** Render column visibility options.
	 *
	 * @param string[] $visible_columns Visible columns.
	 */
	private function render_column_options( $visible_columns ) {
		$columns = $this->get_columns();
		?>
		<details class="cto-column-options">
			<summary><?php esc_html_e( 'Spalten ein-/ausblenden', 'content-taxonomy-overview' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cto_save_columns' ); ?>
				<input type="hidden" name="action" value="cto_save_columns" />
				<?php foreach ( $columns as $key => $label ) : ?>
					<label><input type="checkbox" name="columns[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $visible_columns, true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				<?php submit_button( __( 'Spalten speichern', 'content-taxonomy-overview' ), 'secondary', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}

	/** Save column visibility options. */
	public function handle_save_columns() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}

		check_admin_referer( 'cto_save_columns' );
		$allowed = array_keys( $this->get_columns() );
		$columns = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['columns'] ) ) : array();
		$columns = array_values( array_intersect( $allowed, $columns ) );

		update_user_meta( get_current_user_id(), 'cto_visible_columns', empty( $columns ) ? $allowed : $columns );
		wp_safe_redirect( add_query_arg( array( 'page' => 'content-taxonomy-overview', 'cto_notice' => 'columns_saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Render table.
	 *
	 * @param WP_Post[] $posts Posts.
	 */
	private function render_table( $posts, $visible_columns ) {
		$columns = $this->get_columns();
		?>
		<table class="widefat fixed striped cto-table">
		<thead><tr><?php foreach ( $columns as $key => $label ) : ?><?php if ( in_array( $key, $visible_columns, true ) ) : ?><th><?php echo esc_html( $label ); ?></th><?php endif; ?><?php endforeach; ?></tr></thead>
		<tbody>
		<?php if ( empty( $posts ) ) : ?>
			<tr><td colspan="<?php echo esc_attr( count( $visible_columns ) ); ?>"><?php esc_html_e( 'Keine Inhalte gefunden.', 'content-taxonomy-overview' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $posts as $post ) : $this->render_row( $post, $visible_columns ); endforeach; ?>
		</tbody></table>
		<?php
	}

	/** Render row.
	 *
	 * @param WP_Post $post Post.
	 */
	private function render_row( $post, $visible_columns ) {
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
		$action       = wp_nonce_url( add_query_arg( array( 'action' => 'cto_analyze_single', 'post_id' => $post->ID ), admin_url( 'admin-post.php' ) ), 'cto_analyze_single_' . $post->ID );
		$status       = (string) get_post_meta( $post->ID, '_cto_analysis_status', true );
		$status_class = $this->get_status_class( $status );
		$row          = array(
			'title'        => '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>',
			'post_type'    => esc_html( $post->post_type ),
			'status'       => esc_html( $post->post_status ),
			'date'         => esc_html( get_the_modified_date( 'd.m.Y', $post ) ),
			'categories'   => esc_html( implode( ', ', isset( $tax['category_terms'] ) ? $tax['category_terms'] : array() ) ),
			'tags'         => esc_html( implode( ', ', isset( $tax['tag_terms'] ) ? $tax['tag_terms'] : array() ) ),
			'custom_tax'   => esc_html( implode( ' | ', $custom ) ),
			'words'        => esc_html( isset( $content['word_count'] ) ? $content['word_count'] : '—' ),
			'internal'     => esc_html( isset( $content['internal_links'] ) ? $content['internal_links'] : '—' ),
			'external'     => esc_html( isset( $content['external_links'] ) ? $content['external_links'] : '—' ),
			'h2'           => esc_html( isset( $content['h2_count'] ) ? $content['h2_count'] : '—' ),
			'featured'     => ! empty( $content['featured_image'] ) ? esc_html__( 'Ja', 'content-taxonomy-overview' ) : esc_html__( 'Nein', 'content-taxonomy-overview' ),
			'tax_score'    => esc_html( get_post_meta( $post->ID, '_cto_taxonomy_score', true ) ),
			'struct_score' => esc_html( get_post_meta( $post->ID, '_cto_structure_score', true ) ),
			'total_score'  => '<strong>' . esc_html( get_post_meta( $post->ID, '_cto_total_score', true ) ) . '</strong>',
			'notice'       => '<span class="cto-status cto-status-' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span>',
			'action'       => '<a class="button button-small" href="' . esc_url( $action ) . '">' . esc_html__( 'Neu analysieren', 'content-taxonomy-overview' ) . '</a>',
		);
		?>
		<tr>
			<?php foreach ( $row as $key => $value ) : ?>
				<?php if ( in_array( $key, $visible_columns, true ) ) : ?>
					<td><?php echo wp_kses_post( $value ); ?></td>
				<?php endif; ?>
			<?php endforeach; ?>
		</tr>
		<?php
	}


	/** Map a status label to a stable CSS class.
	 *
	 * @param string $status Status label.
	 * @return string
	 */
	private function get_status_class( $status ) {
		if ( 'OK' === $status ) {
			return 'ok';
		}

		if ( 'Prüfen' === $status ) {
			return 'review';
		}

		if ( 'Unvollständig' === $status ) {
			return 'incomplete';
		}

		return 'unknown';
	}

	/** Render pagination controls.
	 *
	 * @param WP_Query $query Query.
	 * @param array    $filters Filters.
	 */
	private function render_pagination( $query, $filters ) {
		$total_pages = (int) $query->max_num_pages;
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_args = array_filter(
			array(
				'page'          => 'content-taxonomy-overview',
				'cto_post_type' => $filters['post_type'],
				'cto_status'    => $filters['status'],
				'cto_rating'    => $filters['rating'],
				's'             => $filters['s'],
				'orderby'       => $filters['orderby'],
				'order'         => $filters['order'],
				'per_page'      => $filters['per_page'],
			),
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
					'format'    => '',
					'current'   => max( 1, (int) $filters['paged'] ),
					'total'     => $total_pages,
					'prev_text' => __( '&laquo;', 'content-taxonomy-overview' ),
					'next_text' => __( '&raquo;', 'content-taxonomy-overview' ),
				)
			)
		);
		echo '</div></div>';
	}
}
