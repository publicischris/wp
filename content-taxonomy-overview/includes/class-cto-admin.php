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
		add_action( 'admin_post_cto_ai_analyze_single', array( $this, 'handle_ai_analyze_single' ) );
		add_action( 'admin_post_cto_ai_analyze_all', array( $this, 'handle_ai_analyze_all' ) );
		add_action( 'admin_post_cto_ai_recommendation_action', array( $this, 'handle_recommendation_action' ) );
		add_action( 'admin_post_cto_save_columns', array( $this, 'handle_save_columns' ) );
		add_action( 'wp_ajax_cto_analyze_single', array( $this, 'ajax_analyze_single' ) );
		add_action( 'wp_ajax_cto_ai_analyze_single', array( $this, 'ajax_ai_analyze_single' ) );
		add_action( 'wp_ajax_cto_recommendation_action', array( $this, 'ajax_recommendation_action' ) );
	}

	/** Register menu. */
	public function register_menu() {
		add_menu_page( __( 'Content Compass', 'content-taxonomy-overview' ), __( 'Content Compass', 'content-taxonomy-overview' ), 'manage_options', 'content-taxonomy-overview', array( $this, 'render_page' ), 'dashicons-category', 58 );
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
		wp_localize_script( 'cto-admin', 'ctoAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'working' => __( 'Wird verarbeitet…', 'content-taxonomy-overview' ),
			'success' => __( 'Aktion erfolgreich ausgeführt.', 'content-taxonomy-overview' ),
			'error'   => __( 'Die Aktion ist fehlgeschlagen.', 'content-taxonomy-overview' ),
		) );
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
			<h1><?php esc_html_e( 'Content Compass', 'content-taxonomy-overview' ); ?></h1>
			<?php $this->render_notices(); ?>
			<?php $this->render_summary(); ?>
			<?php $this->render_score_explanation(); ?>
			<?php $this->render_column_options( $columns ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cto-actions">
				<?php wp_nonce_field( 'cto_analyze_all' ); ?>
				<input type="hidden" name="action" value="cto_analyze_all" />
				<?php submit_button( __( 'Alle Inhalte neu analysieren', 'content-taxonomy-overview' ), 'primary', 'submit', false ); ?>
			</form>
			<?php if ( CTO_AI_Service::is_configured() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cto-actions">
					<?php wp_nonce_field( 'cto_ai_analyze_all' ); ?>
					<input type="hidden" name="action" value="cto_ai_analyze_all" />
					<?php submit_button( __( 'Alle Inhalte mit KI analysieren', 'content-taxonomy-overview' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<form method="get" class="cto-filters">
				<input type="hidden" name="page" value="content-taxonomy-overview" />
				<?php $this->render_filters( $filters ); ?>
			</form>
			<?php $this->render_pagination( $query, $filters, 'top' ); ?>
			<?php $this->render_table( $query->posts, $columns ); ?>
			<?php $this->render_pagination( $query, $filters, 'bottom' ); ?>
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
		$this->invalidate_content_caches( $post_id );
		$this->analyzer->analyze_post( $post_id );
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : add_query_arg( array( 'page' => 'content-taxonomy-overview' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( add_query_arg( array( 'cto_notice' => 'analyzed_single' ), $redirect ) );
		exit;
	}


	/** Handle single AI analysis. */
	public function handle_ai_analyze_single() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}

		check_admin_referer( 'cto_ai_analyze_single_' . $post_id );
		$ai_service = new CTO_AI_Service();
		$result     = $ai_service->analyze_with_ai( $post_id );
		$notice     = is_wp_error( $result ) ? 'ai_failed' : 'ai_analyzed_single';
		$args       = array(
			'page'       => 'content-taxonomy-overview',
			'cto_notice' => $notice,
		);

		if ( is_wp_error( $result ) ) {
			$args['cto_error'] = $result->get_error_message();
		}

		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : add_query_arg( array( 'page' => 'content-taxonomy-overview' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}


	/** Handle limited global AI analysis. */
	public function handle_ai_analyze_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}
		check_admin_referer( 'cto_ai_analyze_all' );
		$analyzed = 0;
		$skipped  = 0;
		$errors   = 0;
		$query    = new WP_Query( array( 'post_type' => CTO_Utils::supported_post_types(), 'post_status' => CTO_Utils::supported_statuses(), 'posts_per_page' => 10, 'fields' => 'ids', 'no_found_rows' => true ) );
		$service  = new CTO_AI_Service();
		foreach ( $query->posts as $post_id ) {
			if ( ! CTO_AI_Service::is_configured() ) { $skipped++; continue; }
			$result = $service->analyze_post_with_ai( $post_id );
			if ( is_wp_error( $result ) ) { $errors++; } else { $analyzed++; }
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'content-taxonomy-overview', 'cto_notice' => 'ai_bulk_done', 'cto_analyzed' => $analyzed, 'cto_skipped' => $skipped, 'cto_errors' => $errors ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Handle recommendation workflow action. */
	public function handle_recommendation_action() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$key     = isset( $_GET['rec_key'] ) ? sanitize_text_field( wp_unslash( $_GET['rec_key'] ) ) : '';
		$action  = isset( $_GET['rec_action'] ) ? sanitize_key( wp_unslash( $_GET['rec_action'] ) ) : '';
		if ( ! $post_id || ! $key || ! current_user_can( 'edit_post', $post_id ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) ); }
		check_admin_referer( 'cto_ai_recommendation_' . $post_id . '_' . $key );
		$result   = $this->process_recommendation_action( $post_id, $key, $action );
		$notice   = is_wp_error( $result ) ? 'rec_failed' : 'rec_updated';
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : add_query_arg( array( 'page' => 'content-taxonomy-overview' ), admin_url( 'admin.php' ) );
		$args     = array( 'cto_notice' => $notice );
		if ( is_wp_error( $result ) ) { $args['cto_error'] = $result->get_error_message(); }
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}


	/** AJAX: single rule-based analysis. */
	public function ajax_analyze_single() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'content-taxonomy-overview' ) ), 403 ); }
		check_ajax_referer( 'cto_analyze_single_' . $post_id, 'nonce' );
		$this->invalidate_content_caches( $post_id );
		$result = $this->analyzer->analyze_post( $post_id );
		$this->invalidate_content_caches( $post_id );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( array( 'message' => __( 'Analyse aktualisiert. Kategorien, Tags und Taxonomien wurden neu geladen.', 'content-taxonomy-overview' ), 'scores' => $this->get_score_payload( $post_id ) ) );
	}

	/** AJAX: single AI analysis. */
	public function ajax_ai_analyze_single() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) { wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'content-taxonomy-overview' ) ), 403 ); }
		check_ajax_referer( 'cto_ai_analyze_single_' . $post_id, 'nonce' );
		$service = new CTO_AI_Service();
		$result  = $service->analyze_post_with_ai( $post_id );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( array( 'message' => __( 'KI-Analyse wurde erstellt.', 'content-taxonomy-overview' ), 'scores' => $this->get_score_payload( $post_id ) ) );
	}

	/** AJAX: recommendation workflow action. */
	public function ajax_recommendation_action() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$key     = isset( $_POST['rec_key'] ) ? sanitize_text_field( wp_unslash( $_POST['rec_key'] ) ) : '';
		$action  = isset( $_POST['rec_action'] ) ? sanitize_key( wp_unslash( $_POST['rec_action'] ) ) : '';
		if ( ! $post_id || ! $key || ! current_user_can( 'edit_post', $post_id ) ) { wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'content-taxonomy-overview' ) ), 403 ); }
		check_ajax_referer( 'cto_ai_recommendation_' . $post_id . '_' . $key, 'nonce' );
		$result = $this->process_recommendation_action( $post_id, $key, $action );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		$message = 'accept' === $action ? __( 'Taxonomie übernommen und Analyse aktualisiert.', 'content-taxonomy-overview' ) : __( 'Empfehlungsstatus aktualisiert.', 'content-taxonomy-overview' );
		wp_send_json_success( array( 'message' => $message, 'status' => $result, 'scores' => $this->get_score_payload( $post_id ) ) );
	}

	/** Render notices. */
	private function render_notices() {
		$notice = isset( $_GET['cto_notice'] ) ? sanitize_key( wp_unslash( $_GET['cto_notice'] ) ) : '';
		if ( 'analyzed_all' === $notice ) {
			$count = isset( $_GET['cto_count'] ) ? absint( $_GET['cto_count'] ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Inhalte wurden analysiert.', 'content-taxonomy-overview' ), $count ) ) . '</p></div>';
		} elseif ( 'analyzed_single' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analyse aktualisiert. Kategorien, Tags und Taxonomien wurden neu geladen.', 'content-taxonomy-overview' ) . '</p></div>';
		} elseif ( 'columns_saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Spaltenauswahl gespeichert.', 'content-taxonomy-overview' ) . '</p></div>';
		} elseif ( 'ai_analyzed_single' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'KI-Analyse wurde erstellt. Es wurden keine Inhalte oder Taxonomien geändert.', 'content-taxonomy-overview' ) . '</p></div>';
		} elseif ( 'ai_failed' === $notice ) {
			$error = isset( $_GET['cto_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cto_error'] ) ) : __( 'Unknown error.', 'content-taxonomy-overview' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'KI-Analyse fehlgeschlagen: %s', 'content-taxonomy-overview' ), $error ) ) . '</p></div>';
		} elseif ( 'ai_bulk_done' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'KI-Durchlauf beendet: %1$d analysiert, %2$d übersprungen, %3$d Fehler.', 'content-taxonomy-overview' ), absint( $_GET['cto_analyzed'] ?? 0 ), absint( $_GET['cto_skipped'] ?? 0 ), absint( $_GET['cto_errors'] ?? 0 ) ) ) . '</p></div>';
		} elseif ( 'rec_updated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Taxonomie übernommen und Analyse aktualisiert.', 'content-taxonomy-overview' ) . '</p></div>';
		} elseif ( 'rec_failed' === $notice ) {
			$error = isset( $_GET['cto_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cto_error'] ) ) : __( 'Empfehlung konnte nicht übernommen werden. Prüfe Einstellungen und vorhandene Terms.', 'content-taxonomy-overview' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
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
			'ai_status' => isset( $_GET['cto_ai_status'] ) ? sanitize_key( wp_unslash( $_GET['cto_ai_status'] ) ) : '',
			'rec_status'=> isset( $_GET['cto_rec_status'] ) ? sanitize_key( wp_unslash( $_GET['cto_rec_status'] ) ) : '',
			'intent'    => isset( $_GET['cto_intent'] ) ? sanitize_key( wp_unslash( $_GET['cto_intent'] ) ) : '',
			'cluster'   => isset( $_GET['cto_cluster'] ) ? sanitize_text_field( wp_unslash( $_GET['cto_cluster'] ) ) : '',
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

		$meta_query = array();
		if ( in_array( $filters['rating'], array( 'OK', 'Prüfen', 'Unvollständig' ), true ) ) { $meta_query[] = array( 'key' => '_cto_analysis_status', 'value' => $filters['rating'] ); }
		if ( 'analyzed' === $filters['ai_status'] ) { $meta_query[] = array( 'key' => '_cto_ai_status', 'value' => 'analyzed' ); }
		if ( 'error' === $filters['ai_status'] ) { $meta_query[] = array( 'key' => '_cto_ai_status', 'value' => 'error' ); }
		if ( 'not_analyzed' === $filters['ai_status'] ) { $meta_query[] = array( 'key' => '_cto_ai_status', 'compare' => 'NOT EXISTS' ); }
		if ( '' !== $filters['intent'] && in_array( $filters['intent'], $this->search_intentions(), true ) ) { $meta_query[] = array( 'key' => '_cto_ai_search_intent', 'value' => $filters['intent'], 'compare' => '=' ); }
		if ( '' !== $filters['cluster'] ) { $meta_query[] = array( 'key' => '_cto_ai_content_cluster', 'value' => $filters['cluster'], 'compare' => '=' ); }
		if ( in_array( $filters['rec_status'], array( 'open', 'accepted', 'ignored' ), true ) ) { $meta_query[] = array( 'key' => '_cto_ai_recommendation_status', 'value' => $filters['rec_status'], 'compare' => 'LIKE' ); }
		if ( ! empty( $meta_query ) ) { $args['meta_query'] = $meta_query; } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return new WP_Query( $args );
	}

	/** Render filters.
	 *
	 * @param array $filters Filters.
	 */
	private function render_filters( $filters ) {
		$clusters = $this->get_available_content_clusters();
		?>
		<select name="cto_post_type"><option value=""><?php esc_html_e( 'Alle Post Types', 'content-taxonomy-overview' ); ?></option><?php foreach ( CTO_Utils::supported_post_type_objects() as $post_type => $post_type_object ) : ?><option value="<?php echo esc_attr( $post_type ); ?>" <?php selected( $filters['post_type'], $post_type ); ?>><?php echo esc_html( $post_type_object->labels->name ); ?></option><?php endforeach; ?></select>
		<select name="cto_status"><option value=""><?php esc_html_e( 'Alle Status', 'content-taxonomy-overview' ); ?></option><option value="publish" <?php selected( $filters['status'], 'publish' ); ?>>Published</option><option value="draft" <?php selected( $filters['status'], 'draft' ); ?>>Draft</option></select>
		<select name="cto_rating"><option value=""><?php esc_html_e( 'Alle Bewertungen', 'content-taxonomy-overview' ); ?></option><option value="OK" <?php selected( $filters['rating'], 'OK' ); ?>>OK</option><option value="Prüfen" <?php selected( $filters['rating'], 'Prüfen' ); ?>>Prüfen</option><option value="Unvollständig" <?php selected( $filters['rating'], 'Unvollständig' ); ?>>Unvollständig</option></select>
		<input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Titel suchen', 'content-taxonomy-overview' ); ?>" />
		<select name="orderby"><option value="date" <?php selected( $filters['orderby'], 'date' ); ?>>Datum</option><option value="score" <?php selected( $filters['orderby'], 'score' ); ?>>Score</option><option value="status" <?php selected( $filters['orderby'], 'status' ); ?>>Status</option><option value="post_type" <?php selected( $filters['orderby'], 'post_type' ); ?>>Post Type</option></select>
		<select name="order"><option value="DESC" <?php selected( $filters['order'], 'DESC' ); ?>>DESC</option><option value="ASC" <?php selected( $filters['order'], 'ASC' ); ?>>ASC</option></select>
		<select name="per_page"><option value="20" <?php selected( $filters['per_page'], 20 ); ?>>20</option><option value="50" <?php selected( $filters['per_page'], 50 ); ?>>50</option><option value="100" <?php selected( $filters['per_page'], 100 ); ?>>100</option></select>
		<select name="cto_ai_status"><option value=""><?php esc_html_e( 'KI-Status alle', 'content-taxonomy-overview' ); ?></option><option value="not_analyzed" <?php selected( $filters['ai_status'], 'not_analyzed' ); ?>>Nicht analysiert</option><option value="analyzed" <?php selected( $filters['ai_status'], 'analyzed' ); ?>>Analysiert</option><option value="error" <?php selected( $filters['ai_status'], 'error' ); ?>>Fehler</option></select>
		<select name="cto_rec_status"><option value=""><?php esc_html_e( 'Empfehlungen alle', 'content-taxonomy-overview' ); ?></option><option value="open" <?php selected( $filters['rec_status'], 'open' ); ?>>Offen</option><option value="accepted" <?php selected( $filters['rec_status'], 'accepted' ); ?>>Akzeptiert</option><option value="ignored" <?php selected( $filters['rec_status'], 'ignored' ); ?>>Ignoriert</option></select>
		<select name="cto_intent"><option value=""><?php esc_html_e( 'Alle Suchintentionen', 'content-taxonomy-overview' ); ?></option><?php foreach ( $this->search_intentions() as $intent ) : ?><option value="<?php echo esc_attr( $intent ); ?>" <?php selected( $filters['intent'], $intent ); ?>><?php echo esc_html( $intent ); ?></option><?php endforeach; ?></select>
		<select name="cto_cluster" <?php disabled( empty( $clusters ) ); ?>><option value=""><?php echo empty( $clusters ) ? esc_html__( 'Keine Cluster vorhanden', 'content-taxonomy-overview' ) : esc_html__( 'Alle Content Cluster', 'content-taxonomy-overview' ); ?></option><?php foreach ( $clusters as $cluster ) : ?><option value="<?php echo esc_attr( $cluster ); ?>" <?php selected( $filters['cluster'], $cluster ); ?>><?php echo esc_html( $cluster ); ?></option><?php endforeach; ?></select>
		<input type="hidden" name="paged" value="1" />
		<?php submit_button( __( 'Filtern', 'content-taxonomy-overview' ), 'secondary', 'submit', false ); ?>
		<?php
	}

	/** Get supported search intentions for filter dropdown.
	 *
	 * @return string[]
	 */
	private function search_intentions() {
		return array( 'informational', 'commercial', 'transactional', 'navigational', 'mixed', 'unknown' );
	}

	/** Get existing content clusters for enabled post types.
	 *
	 * @return string[]
	 */
	private function get_available_content_clusters() {
		global $wpdb;
		$post_types = CTO_Utils::supported_post_types();
		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = "
			SELECT DISTINCT pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND pm.meta_value <> ''
			AND p.post_type IN ($placeholders)
			AND p.post_status IN ('publish','draft')
			ORDER BY pm.meta_value ASC
			LIMIT 200
		";
		$values = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( '_cto_ai_content_cluster' ), $post_types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_values( array_filter( array_map( 'sanitize_text_field', (array) $values ) ) );
	}





	/** Render score calculation explanation. */
	private function render_score_explanation() {
		?>
		<details class="cto-score-explanation">
			<summary><strong><?php esc_html_e( 'Wie wird der Score berechnet?', 'content-taxonomy-overview' ); ?></strong></summary>
			<div class="cto-score-explanation-grid">
				<div><h3><?php esc_html_e( 'Taxonomie-Score', 'content-taxonomy-overview' ); ?></h3><ul><li><?php esc_html_e( 'Kategorie vorhanden: +30 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Mindestens ein Tag vorhanden: +20 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Mehr als eine Taxonomie-Zuordnung vorhanden: +20 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Keine Kategorie „Uncategorized“ bzw. „Allgemein“: +20 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Mindestens eine Custom Taxonomy vorhanden, falls relevant: +10 Punkte', 'content-taxonomy-overview' ); ?></li></ul></div>
				<div><h3><?php esc_html_e( 'Struktur-Score', 'content-taxonomy-overview' ); ?></h3><ul><li><?php esc_html_e( 'Wortanzahl über 500 Wörter: +25 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Mindestens eine H2-Überschrift vorhanden: +20 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Featured Image vorhanden: +20 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Mindestens ein interner Link vorhanden: +20 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Meta Description vorhanden, falls Yoast SEO oder Rank Math erkannt wird: +15 Punkte', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( 'Featured Image ALT-Text vorhanden: +10 Punkte', 'content-taxonomy-overview' ); ?></li></ul></div>
				<div><h3><?php esc_html_e( 'Gesamtbewertung', 'content-taxonomy-overview' ); ?></h3><p><?php esc_html_e( 'Kriterien können pro Inhaltstyp als prüfen oder nicht relevant konfiguriert werden. Nicht relevante Kriterien verschlechtern den Score nicht; die Punkte werden auf die maximal relevanten Punkte normalisiert.', 'content-taxonomy-overview' ); ?></p><p><?php esc_html_e( 'Suchintention beschreibt die vermutete Absicht des Nutzers; Content Cluster bezeichnet die thematische Gruppierung des Inhalts.', 'content-taxonomy-overview' ); ?></p><ul><li><?php esc_html_e( '80–100: OK', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( '50–79: Prüfen', 'content-taxonomy-overview' ); ?></li><li><?php esc_html_e( '0–49: Unvollständig', 'content-taxonomy-overview' ); ?></li></ul></div>
			</div>
		</details>
		<?php
	}

	/** Render dashboard summary. */
	private function render_summary() {
		$total = 0;
		foreach ( CTO_Utils::supported_post_types() as $post_type ) {
			$counts = wp_count_posts( $post_type );
			$total += (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 );
		}
		$ok = $this->count_meta( '_cto_analysis_status', 'OK' );
		$review = $this->count_meta( '_cto_analysis_status', 'Prüfen' );
		$incomplete = $this->count_meta( '_cto_analysis_status', 'Unvollständig' );
		$ai = $this->count_meta( '_cto_ai_status', 'analyzed' );
		$open = $this->count_meta_like( '_cto_ai_recommendation_status', 'open' );
		$missing_tax = $this->count_meta( '_cto_taxonomy_score', '0' );
		$links = $this->count_meta_exists( '_cto_ai_internal_link_suggestions' );
		$last = $this->latest_meta_date( '_cto_ai_analyzed_at' );
		echo '<div class="cto-summary"><span>Inhalte: ' . esc_html( $total ) . '</span><span>OK: ' . esc_html( $ok ) . '</span><span>Prüfen: ' . esc_html( $review ) . '</span><span>Unvollständig: ' . esc_html( $incomplete ) . '</span><span>KI analysiert: ' . esc_html( $ai ) . '</span><span>Offene Empfehlungen: ' . esc_html( $open ) . '</span><span>Fehlende Taxonomie: ' . esc_html( $missing_tax ) . '</span><span>Linkvorschläge: ' . esc_html( $links ) . '</span><span>Letzte KI: ' . esc_html( $last ? mysql2date( 'd.m.Y H:i', $last ) : '—' ) . '</span></div>';
	}
	private function count_meta( $key, $value ) { $q = new WP_Query( array( 'post_type' => CTO_Utils::supported_post_types(), 'post_status' => CTO_Utils::supported_statuses(), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => $key, 'meta_value' => $value ) ); return (int) $q->found_posts; }
	private function count_meta_like( $key, $value ) { $q = new WP_Query( array( 'post_type' => CTO_Utils::supported_post_types(), 'post_status' => CTO_Utils::supported_statuses(), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => $key, 'value' => $value, 'compare' => 'LIKE' ) ) ) ); return (int) $q->found_posts; }
	private function count_meta_exists( $key ) { $q = new WP_Query( array( 'post_type' => CTO_Utils::supported_post_types(), 'post_status' => CTO_Utils::supported_statuses(), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => $key, 'compare' => 'EXISTS' ) ) ) ); return (int) $q->found_posts; }
	private function latest_meta_date( $key ) { $q = new WP_Query( array( 'post_type' => CTO_Utils::supported_post_types(), 'post_status' => CTO_Utils::supported_statuses(), 'posts_per_page' => 1, 'meta_key' => $key, 'orderby' => 'meta_value', 'order' => 'DESC' ) ); return ! empty( $q->posts ) ? get_post_meta( $q->posts[0]->ID, $key, true ) : ''; }

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
		$action       = wp_nonce_url( add_query_arg( array( 'action' => 'cto_analyze_single', 'post_id' => $post->ID, 'redirect_to' => $this->get_current_overview_url() ), admin_url( 'admin-post.php' ) ), 'cto_analyze_single_' . $post->ID );
		$ai_action    = wp_nonce_url( add_query_arg( array( 'action' => 'cto_ai_analyze_single', 'post_id' => $post->ID, 'redirect_to' => $this->get_current_overview_url() ), admin_url( 'admin-post.php' ) ), 'cto_ai_analyze_single_' . $post->ID );
		$status       = (string) get_post_meta( $post->ID, '_cto_analysis_status', true );
		$status_class = $this->get_status_class( $status );
		$stale_notice = $this->is_rule_analysis_stale( $post ) ? '<br><span class="cto-stale-rule">' . esc_html__( 'Analyse möglicherweise veraltet', 'content-taxonomy-overview' ) . '</span>' : '';
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
			'notice'       => '<span class="cto-status cto-status-' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span>' . $stale_notice,
			'action'       => '<a class="button button-small cto-ajax-action" href="' . esc_url( $action ) . '" data-cto-ajax="analyze" data-post-id="' . esc_attr( $post->ID ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'cto_analyze_single_' . $post->ID ) ) . '">' . esc_html__( 'Neu analysieren', 'content-taxonomy-overview' ) . '</a>' . ( CTO_AI_Service::is_configured() ? ' <a class="button button-small cto-ajax-action" href="' . esc_url( $ai_action ) . '" data-cto-ajax="ai_analyze" data-post-id="' . esc_attr( $post->ID ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'cto_ai_analyze_single_' . $post->ID ) ) . '">' . esc_html__( 'Mit KI analysieren', 'content-taxonomy-overview' ) . '</a>' : '' ),
		);
		?>
		<tr class="cto-content-row" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<?php foreach ( $row as $key => $value ) : ?>
				<?php if ( in_array( $key, $visible_columns, true ) ) : ?>
					<td data-cto-column="<?php echo esc_attr( $key ); ?>"><?php echo wp_kses_post( $value ); ?></td>
				<?php endif; ?>
			<?php endforeach; ?>
		</tr>
		<?php $this->render_ai_result_row( $post, $visible_columns ); ?>
		<?php
	}





	/** Render score details for one content item.
	 *
	 * @param WP_Post $post Post object.
	 */
	private function render_score_details( $post ) {
		$data    = get_post_meta( $post->ID, '_cto_analysis_data', true );
		$data    = is_array( $data ) ? $data : array();
		$scoring    = isset( $data['scoring'] ) && is_array( $data['scoring'] ) ? $data['scoring'] : $this->build_legacy_score_details( $post, $data );
		$extraction = isset( $data['content']['content_extraction'] ) && is_array( $data['content']['content_extraction'] ) ? $data['content']['content_extraction'] : array();
		$status     = (string) get_post_meta( $post->ID, '_cto_analysis_status', true );
		$details_source = isset( $data['scoring'] ) ? __( 'aktuelle Analyse', 'content-taxonomy-overview' ) : __( 'rekonstruierte Analyse', 'content-taxonomy-overview' );
		?>
		<div class="cto-score-details">
			<h4><?php esc_html_e( 'Score-Details', 'content-taxonomy-overview' ); ?></h4>
			<div class="cto-score-summary">
				<span><?php echo esc_html( array_key_exists( 'relevant', $scoring['taxonomy'] ?? array() ) && empty( $scoring['taxonomy']['relevant'] ) ? __( 'Taxonomie-Score: nicht relevant', 'content-taxonomy-overview' ) : sprintf( __( 'Taxonomie-Score: %s / 100', 'content-taxonomy-overview' ), get_post_meta( $post->ID, '_cto_taxonomy_score', true ) ) ); ?></span>
				<span><?php echo esc_html( array_key_exists( 'relevant', $scoring['structure'] ?? array() ) && empty( $scoring['structure']['relevant'] ) ? __( 'Struktur-Score: nicht relevant', 'content-taxonomy-overview' ) : sprintf( __( 'Struktur-Score: %s / 100', 'content-taxonomy-overview' ), get_post_meta( $post->ID, '_cto_structure_score', true ) ) ); ?></span>
				<span><?php echo esc_html( sprintf( __( 'Gesamt-Score: %s / 100', 'content-taxonomy-overview' ), get_post_meta( $post->ID, '_cto_total_score', true ) ) ); ?></span>
				<span><?php echo esc_html( sprintf( __( 'Status: %s', 'content-taxonomy-overview' ), '' !== $status ? $status : '—' ) ); ?></span>
				<span><?php echo esc_html( sprintf( __( 'Details-Quelle: %s', 'content-taxonomy-overview' ), $details_source ) ); ?></span>
				<span><?php echo esc_html( sprintf( __( 'Schema: %s', 'content-taxonomy-overview' ), $data['analysis_schema_version'] ?? 'legacy' ) ); ?></span>
			</div>
			<div class="cto-score-criteria">
				<div><h5><?php esc_html_e( 'Taxonomie', 'content-taxonomy-overview' ); ?></h5><?php $this->render_criteria_list( $scoring['taxonomy']['criteria'] ?? array() ); ?></div>
				<div><h5><?php esc_html_e( 'Struktur', 'content-taxonomy-overview' ); ?></h5><?php $this->render_criteria_list( $scoring['structure']['criteria'] ?? array() ); ?></div>
			</div>
			<?php $this->render_content_extraction_diagnostics( $extraction ); ?>
		</div>
		<?php
	}



	/** Render content extraction diagnostics. */
	private function render_content_extraction_diagnostics( $extraction ) {
		if ( empty( $extraction ) ) {
			return;
		}
		$has_text = ! empty( $extraction['analyzable_text_detected'] );
		?>
		<div class="cto-content-diagnostics">
			<h5><?php esc_html_e( 'Content-Extraktion', 'content-taxonomy-overview' ); ?></h5>
			<ul>
				<li><?php echo esc_html( sprintf( __( 'Content-Quelle: %s', 'content-taxonomy-overview' ), $extraction['source'] ?? '—' ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Extraktionsstrategie: %s', 'content-taxonomy-overview' ), $extraction['extraction_strategy_used'] ?? '—' ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Raw-Länge: %d', 'content-taxonomy-overview' ), absint( $extraction['raw_content_length'] ?? $extraction['raw_length'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Avada/Fusion Shortcodes erkannt: %s', 'content-taxonomy-overview' ), ! empty( $extraction['fusion_shortcodes_detected'] ) ? __( 'ja', 'content-taxonomy-overview' ) : __( 'nein', 'content-taxonomy-overview' ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Analysierbarer Text erkannt: %s', 'content-taxonomy-overview' ), $has_text ? __( 'ja', 'content-taxonomy-overview' ) : __( 'nein', 'content-taxonomy-overview' ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Textlänge nach Bereinigung: %d', 'content-taxonomy-overview' ), absint( $extraction['clean_text_length'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Linkquellen erkannt: %1$d gesamt, %2$d intern, %3$d extern', 'content-taxonomy-overview' ), absint( $extraction['links_detected'] ?? 0 ), absint( $extraction['internal_links_detected'] ?? 0 ), absint( $extraction['external_links_detected'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Wortanzahl nach Bereinigung: %d', 'content-taxonomy-overview' ), absint( $extraction['cleaned_text_word_count'] ?? 0 ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Render-Fallback verwendet: %s', 'content-taxonomy-overview' ), ! empty( $extraction['rendered_fallback_used'] ) ? __( 'ja', 'content-taxonomy-overview' ) : __( 'nein', 'content-taxonomy-overview' ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Regex-Fehler: %s', 'content-taxonomy-overview' ), ! empty( $extraction['shortcode_cleanup_error'] ) ? __( 'ja', 'content-taxonomy-overview' ) : __( 'nein', 'content-taxonomy-overview' ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Wortzählung: %s', 'content-taxonomy-overview' ), $extraction['word_count_method'] ?? '—' ) ); ?></li>
			</ul>
			<?php if ( ! empty( $extraction['text_sample'] ) ) : ?><p class="description"><strong><?php esc_html_e( 'Textauszug:', 'content-taxonomy-overview' ); ?></strong> <?php echo esc_html( $extraction['text_sample'] ); ?></p><?php endif; ?>
			<?php if ( ! $has_text ) : ?>
				<p class="description"><?php esc_html_e( 'Es konnte kein analysierbarer Text aus dem gespeicherten Inhalt extrahiert werden. Der Inhalt liegt möglicherweise in Page-Builder-Metafeldern oder wird dynamisch erzeugt.', 'content-taxonomy-overview' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Build fallback details for posts analyzed before criteria storage existed. */
	private function build_legacy_score_details( $post, $data ) {
		$tax     = isset( $data['taxonomies'] ) && is_array( $data['taxonomies'] ) ? $data['taxonomies'] : array();
		$content = isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array();
		return array(
			'taxonomy'  => array( 'criteria' => array(
				array( 'label' => __( 'Kategorie vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $tax['has_category'] ), 'relevant' => ! empty( $tax['category_taxonomy_exists'] ), 'points' => ( ! empty( $tax['has_category'] ) || empty( $tax['category_taxonomy_exists'] ) ) ? 30 : 0, 'max_points' => 30 ),
				array( 'label' => __( 'Tag vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $tax['has_tag'] ), 'relevant' => ! empty( $tax['tag_taxonomy_exists'] ), 'points' => ( ! empty( $tax['has_tag'] ) || empty( $tax['tag_taxonomy_exists'] ) ) ? 20 : 0, 'max_points' => 20 ),
				array( 'label' => __( 'Mehr als eine Taxonomie-Zuordnung', 'content-taxonomy-overview' ), 'met' => (int) ( $tax['total_terms'] ?? 0 ) > 1, 'relevant' => true, 'points' => ( (int) ( $tax['total_terms'] ?? 0 ) > 1 ) ? 20 : 0, 'max_points' => 20 ),
				array( 'label' => __( 'Keine Uncategorized/Allgemein-Kategorie', 'content-taxonomy-overview' ), 'met' => empty( $tax['has_default_category'] ), 'relevant' => ! empty( $tax['category_taxonomy_exists'] ), 'points' => ( empty( $tax['category_taxonomy_exists'] ) || empty( $tax['has_default_category'] ) ) ? 20 : 0, 'max_points' => 20 ),
				array( 'label' => __( 'Custom Taxonomy vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $tax['has_custom_taxonomy_term'] ), 'relevant' => ! empty( $tax['custom_taxonomies_exist'] ), 'points' => ( ! empty( $tax['custom_taxonomies_exist'] ) && ! empty( $tax['has_custom_taxonomy_term'] ) ) ? 10 : 0, 'max_points' => 10 ),
			) ),
			'structure' => array( 'criteria' => array(
				array( 'label' => __( 'Mehr als 500 Wörter', 'content-taxonomy-overview' ), 'met' => (int) ( $content['word_count'] ?? 0 ) > 500, 'relevant' => true, 'points' => ( (int) ( $content['word_count'] ?? 0 ) > 500 ) ? 25 : 0, 'max_points' => 25 ),
				array( 'label' => __( 'H2 vorhanden', 'content-taxonomy-overview' ), 'met' => (int) ( $content['h2_count'] ?? 0 ) > 0, 'relevant' => true, 'points' => ( (int) ( $content['h2_count'] ?? 0 ) > 0 ) ? 20 : 0, 'max_points' => 20 ),
				array( 'label' => __( 'Featured Image vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $content['featured_image'] ), 'relevant' => true, 'points' => ! empty( $content['featured_image'] ) ? 20 : 0, 'max_points' => 20 ),
				array( 'label' => __( 'Interner Link vorhanden', 'content-taxonomy-overview' ), 'met' => (int) ( $content['internal_links'] ?? 0 ) > 0, 'relevant' => true, 'points' => ( (int) ( $content['internal_links'] ?? 0 ) > 0 ) ? 20 : 0, 'max_points' => 20 ),
				array( 'label' => __( 'Meta Description vorhanden', 'content-taxonomy-overview' ), 'met' => ! empty( $content['meta_description_present'] ), 'relevant' => ! empty( $content['seo_plugin_detected'] ), 'points' => ( ! empty( $content['seo_plugin_detected'] ) && ! empty( $content['meta_description_present'] ) ) ? 15 : 0, 'max_points' => 15 ),
			) ),
		);
	}

	/** Render criteria list. */
	private function render_criteria_list( $criteria ) {
		echo '<ul class="cto-criteria-list">';
		foreach ( (array) $criteria as $criterion ) {
			$relevant = array_key_exists( 'relevant', (array) $criterion ) ? (bool) $criterion['relevant'] : true;
			$met      = ! empty( $criterion['met'] );
			$state    = $relevant ? ( $met ? 'met' : 'unmet' ) : 'na';
			$label    = isset( $criterion['label'] ) ? $criterion['label'] : '';
			$points   = isset( $criterion['points'] ) ? (int) $criterion['points'] : 0;
			$max      = isset( $criterion['max_points'] ) ? (int) $criterion['max_points'] : 0;
			echo '<li><span class="cto-criterion-label">' . esc_html( $label ) . '</span> ' . wp_kses_post( $this->criterion_badge( $state ) ) . ' <span class="description">' . esc_html( sprintf( __( '%1$d / %2$d Punkte', 'content-taxonomy-overview' ), $points, $max ) ) . '</span></li>';
		}
		echo '</ul>';
	}

	/** Get criterion badge markup. */
	private function criterion_badge( $state ) {
		$labels = array( 'met' => __( 'erfüllt', 'content-taxonomy-overview' ), 'unmet' => __( 'nicht erfüllt', 'content-taxonomy-overview' ), 'na' => __( 'nicht relevant', 'content-taxonomy-overview' ) );
		$state  = isset( $labels[ $state ] ) ? $state : 'na';
		return '<span class="cto-criterion cto-criterion-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ) . '</span>';
	}

	/** Filter stored AI data before display so old invalid recommendations do not appear.
	 *
	 * @param WP_Post $post Post object.
	 * @param array   $ai_data Stored AI data.
	 * @return array
	 */
	private function filter_ai_data_for_post_type( $post, $ai_data ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		$taxonomies = is_array( $taxonomies ) ? $taxonomies : array();

		if ( ! in_array( 'category', $taxonomies, true ) ) {
			$ai_data['recommended_categories'] = array();
		}

		if ( ! in_array( 'post_tag', $taxonomies, true ) ) {
			$ai_data['recommended_tags'] = array();
		}

		$ai_data['recommended_custom_taxonomies'] = array_values(
			array_filter(
				(array) ( $ai_data['recommended_custom_taxonomies'] ?? array() ),
				static function ( $item ) use ( $post, $taxonomies ) {
					$taxonomy = isset( $item['taxonomy'] ) ? sanitize_key( $item['taxonomy'] ) : '';
					return '' !== $taxonomy && in_array( $taxonomy, $taxonomies, true ) && taxonomy_exists( $taxonomy ) && is_object_in_taxonomy( $post->post_type, $taxonomy );
				}
			)
		);

		return $ai_data;
	}



	/** Invalidate post, term and meta caches before reading fresh analysis data. */
	private function invalidate_content_caches( $post_id ) {
		$post = get_post( $post_id );
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
		if ( $post ) {
			clean_object_term_cache( $post_id, $post->post_type );
		}
	}

	/** Get fresh term column values from current WordPress term assignments. */
	private function get_current_term_columns( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return array( 'categories' => '—', 'tags' => '—', 'custom_tax' => '—' ); }
		clean_object_term_cache( $post_id, $post->post_type );
		$categories = is_object_in_taxonomy( $post->post_type, 'category' ) ? wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) ) : array();
		$tags       = is_object_in_taxonomy( $post->post_type, 'post_tag' ) ? wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) ) : array();
		$custom     = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $taxonomy_object ) {
			if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) { continue; }
			$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) { $custom[] = $taxonomy_object->labels->name . ': ' . implode( ', ', $terms ); }
		}
		return array(
			'categories' => ! is_wp_error( $categories ) && ! empty( $categories ) ? implode( ', ', $categories ) : '—',
			'tags'       => ! is_wp_error( $tags ) && ! empty( $tags ) ? implode( ', ', $tags ) : '—',
			'custom_tax' => ! empty( $custom ) ? implode( ' | ', $custom ) : '—',
		);
	}

	/** Get current score values for AJAX UI updates.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_score_payload( $post_id ) {
		$status = (string) get_post_meta( $post_id, '_cto_analysis_status', true );
		$data   = get_post_meta( $post_id, '_cto_analysis_data', true );
		$content = is_array( $data ) && isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array();
		$terms   = $this->get_current_term_columns( $post_id );
		return array(
			'tax_score'    => (string) get_post_meta( $post_id, '_cto_taxonomy_score', true ),
			'struct_score' => (string) get_post_meta( $post_id, '_cto_structure_score', true ),
			'total_score'  => (string) get_post_meta( $post_id, '_cto_total_score', true ),
			'notice'       => $status,
			'notice_class' => $this->get_status_class( $status ),
			'words'        => (string) ( $content['word_count'] ?? '—' ),
			'internal'     => (string) ( $content['internal_links'] ?? '—' ),
			'external'     => (string) ( $content['external_links'] ?? '—' ),
			'h2'           => (string) ( $content['h2_count'] ?? '—' ),
			'categories'   => $terms['categories'],
			'tags'         => $terms['tags'],
			'custom_tax'   => $terms['custom_tax'],
		);
	}

	/** Render stored AI recommendations below a content row.
	 *
	 * @param WP_Post  $post Post object.
	 * @param string[] $visible_columns Visible columns.
	 */
	private function render_ai_result_row( $post, $visible_columns ) {
		$ai_data = get_post_meta( $post->ID, '_cto_ai_analysis_data', true );
		$ai_data = is_array( $ai_data ) ? $this->filter_ai_data_for_post_type( $post, $ai_data ) : array();

		?>
		<tr class="cto-ai-result-row">
			<td colspan="<?php echo esc_attr( count( $visible_columns ) ); ?>">
				<details class="cto-ai-details"><summary><strong><?php esc_html_e( 'Details anzeigen', 'content-taxonomy-overview' ); ?></strong>
				<?php if ( ! empty( $ai_data['analyzed_at'] ) ) : ?>
					<span class="description">— <?php echo esc_html( mysql2date( 'd.m.Y H:i', $ai_data['analyzed_at'] ) ); ?></span>
				<?php endif; ?>
				</summary>
				<?php $this->render_score_details( $post ); ?>
				<?php if ( ! empty( $ai_data ) ) : ?>
				<?php if ( $this->is_ai_analysis_stale( $post->ID ) ) : ?><p class="notice notice-warning cto-stale-ai"><?php esc_html_e( 'Die KI-Analyse basiert möglicherweise auf einem älteren Stand. Bitte bei Bedarf erneut mit KI analysieren.', 'content-taxonomy-overview' ); ?></p><?php endif; ?>
				<div class="cto-ai-grid">
					<?php $this->render_ai_field( __( 'Hauptthema', 'content-taxonomy-overview' ), isset( $ai_data['main_topic'] ) ? $ai_data['main_topic'] : '' ); ?>
					<?php $this->render_ai_field( __( 'Kategorien', 'content-taxonomy-overview' ), isset( $ai_data['recommended_categories'] ) ? $ai_data['recommended_categories'] : array() ); ?>
					<?php $this->render_ai_field( __( 'Tags', 'content-taxonomy-overview' ), isset( $ai_data['recommended_tags'] ) ? $ai_data['recommended_tags'] : array() ); ?>
					<?php $this->render_ai_field( __( 'Custom Taxonomies', 'content-taxonomy-overview' ), isset( $ai_data['recommended_custom_taxonomies'] ) ? $ai_data['recommended_custom_taxonomies'] : array() ); ?>
					<?php $this->render_ai_field( __( 'Content Cluster', 'content-taxonomy-overview' ), isset( $ai_data['content_cluster'] ) ? $ai_data['content_cluster'] : '' ); ?>
					<?php $this->render_ai_field( __( 'Suchintention', 'content-taxonomy-overview' ), isset( $ai_data['search_intent'] ) ? $ai_data['search_intent'] : '' ); ?>
					<?php $this->render_ai_field( __( 'Interne Links', 'content-taxonomy-overview' ), isset( $ai_data['internal_link_suggestions'] ) ? $ai_data['internal_link_suggestions'] : array() ); ?>
					<?php $this->render_ai_field( __( 'Tonalität', 'content-taxonomy-overview' ), isset( $ai_data['tone_assessment'] ) ? $ai_data['tone_assessment'] : '' ); ?>
					<?php $this->render_ai_field( __( 'Begründung', 'content-taxonomy-overview' ), isset( $ai_data['summary'] ) ? $ai_data['summary'] : '' ); ?>
				</div>
				<?php $this->render_recommendation_workflow( $post, $ai_data ); ?>
				<p class="description"><?php esc_html_e( 'Hinweis: Die KI-Analyse ist nur eine Empfehlung. Es wurden keine Inhalte, Kategorien, Tags oder Taxonomien automatisch geändert.', 'content-taxonomy-overview' ); ?></p>
				<?php endif; ?></details>
			</td>
		</tr>
		<?php
	}


	/** Render recommendation workflow actions. */
	private function render_recommendation_workflow( $post, $ai_data ) {
		$statuses = get_post_meta( $post->ID, '_cto_ai_recommendation_status', true );
		$statuses = is_array( $statuses ) ? $statuses : array();
		echo '<div class="cto-workflow"><h4>' . esc_html__( 'Empfehlungen prüfen', 'content-taxonomy-overview' ) . '</h4>';
		$this->render_workflow_items( $post, 'recommended_categories', __( 'Kategorie', 'content-taxonomy-overview' ), $ai_data['recommended_categories'] ?? array(), $statuses );
		$this->render_workflow_items( $post, 'recommended_tags', __( 'Tag', 'content-taxonomy-overview' ), $ai_data['recommended_tags'] ?? array(), $statuses );
		foreach ( (array) ( $ai_data['recommended_custom_taxonomies'] ?? array() ) as $tax_index => $tax_item ) {
			$this->render_workflow_items( $post, 'recommended_custom_taxonomies', 'Custom Taxonomy: ' . sanitize_key( $tax_item['taxonomy'] ?? '' ), $tax_item['terms'] ?? array(), $statuses, $tax_index );
		}
		$this->render_workflow_items( $post, 'internal_link_suggestions', __( 'Interner Link', 'content-taxonomy-overview' ), $ai_data['internal_link_suggestions'] ?? array(), $statuses, 0, true );
		$this->render_workflow_items( $post, 'recommendations', __( 'Allgemein', 'content-taxonomy-overview' ), $ai_data['recommendations'] ?? array(), $statuses );
		echo '</div>';
	}

	/** Render one workflow item group. */
	private function render_workflow_items( $post, $group, $label, $items, $statuses, $parent_index = 0, $link_only = false ) {
		foreach ( (array) $items as $index => $item ) {
			$key_index  = 'recommended_custom_taxonomies' === $group ? $parent_index . '_' . $index : $index;
			$key        = CTO_AI_Service::recommendation_key( $group, $key_index, $item );
			$status     = isset( $statuses[ $key ] ) ? $statuses[ $key ] : 'open';
			$name       = is_array( $item ) ? ( $item['name'] ?? $item['title'] ?? $item['recommendation'] ?? '' ) : (string) $item;
			$reason     = is_array( $item ) ? ( $item['reason'] ?? '' ) : '';
			$confidence = is_array( $item ) && isset( $item['confidence'] ) ? ' (' . esc_html( $item['confidence'] ) . ')' : '';
			$state      = $this->get_recommendation_application_state( $post, $group, $item, $parent_index, $link_only, $status );
			if ( in_array( $state['state'], array( 'accepted', 'ignored' ), true ) ) { continue; }
			echo '<div class="cto-workflow-item" data-rec-key="' . esc_attr( $key ) . '" data-rec-status="' . esc_attr( $status ) . '"><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $name ) . esc_html( $confidence ) . '<br><span class="description">' . esc_html( $reason ) . '</span><br><span class="cto-rec-state cto-rec-state-' . esc_attr( $state['state'] ) . '">' . esc_html( $state['label'] ) . '</span> ';
			if ( $state['can_accept'] ) { echo wp_kses_post( $this->recommendation_link( $post->ID, $key, 'accept', __( 'Übernehmen', 'content-taxonomy-overview' ) ) ) . ' '; }
			elseif ( 'already_applied' === $state['state'] ) { echo wp_kses_post( $this->recommendation_link( $post->ID, $key, 'checked', __( 'Als geprüft markieren', 'content-taxonomy-overview' ) ) ) . ' '; }
			else { echo wp_kses_post( $this->recommendation_link( $post->ID, $key, 'checked', __( 'Als geprüft markieren', 'content-taxonomy-overview' ) ) ) . ' '; }
			echo wp_kses_post( $this->recommendation_link( $post->ID, $key, 'ignore', __( 'Ignorieren', 'content-taxonomy-overview' ) ) );
			echo ' ' . wp_kses_post( $this->recommendation_link( $post->ID, $key, 'reset', __( 'Zurücksetzen', 'content-taxonomy-overview' ) ) );
			echo '</div>';
		}
	}

	/** Determine display/action state for a recommendation. */
	private function get_recommendation_application_state( $post, $group, $item, $parent_index, $link_only, $workflow_status ) {
		if ( 'ignored' === $workflow_status ) { return array( 'state' => 'ignored', 'label' => __( 'Ignoriert', 'content-taxonomy-overview' ), 'can_accept' => false ); }
		if ( 'accepted' === $workflow_status ) { return array( 'state' => 'accepted', 'label' => __( 'Geprüft', 'content-taxonomy-overview' ), 'can_accept' => false ); }
		if ( $link_only || 'recommendations' === $group ) { return array( 'state' => 'review', 'label' => __( 'Prüfaufgabe', 'content-taxonomy-overview' ), 'can_accept' => false ); }
		$taxonomy = '';
		$custom   = false;
		if ( 'recommended_categories' === $group ) { $taxonomy = 'category'; }
		elseif ( 'recommended_tags' === $group ) { $taxonomy = 'post_tag'; }
		elseif ( 'recommended_custom_taxonomies' === $group ) { $data = get_post_meta( $post->ID, '_cto_ai_analysis_data', true ); $tax_item = $data['recommended_custom_taxonomies'][ $parent_index ] ?? array(); $taxonomy = sanitize_key( $tax_item['taxonomy'] ?? '' ); $custom = true; }
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) { return array( 'state' => 'not_applicable', 'label' => __( 'Nicht anwendbar', 'content-taxonomy-overview' ), 'can_accept' => false ); }
		$name = $this->get_recommendation_term_name( $item );
		$term = term_exists( $name, $taxonomy );
		if ( $term ) {
			$term_id = is_array( $term ) ? absint( $term['term_id'] ) : absint( $term );
			$assigned = has_term( $term_id, $taxonomy, $post );
			return $assigned ? array( 'state' => 'already_applied', 'label' => __( 'Bereits vorhanden', 'content-taxonomy-overview' ), 'can_accept' => false ) : array( 'state' => 'can_apply', 'label' => __( 'Kann übernommen werden', 'content-taxonomy-overview' ), 'can_accept' => true );
		}
		$settings = CTO_AI_Service::get_settings();
		$allowed  = $custom ? ! empty( $settings['allow_create_custom_terms'] ) : ! empty( $settings['allow_create_terms'] );
		return $allowed ? array( 'state' => 'can_create', 'label' => __( 'Kann erstellt und übernommen werden', 'content-taxonomy-overview' ), 'can_accept' => true ) : array( 'state' => 'not_allowed', 'label' => __( 'Nicht vorhanden, Erstellung nicht erlaubt', 'content-taxonomy-overview' ), 'can_accept' => false );
	}

	/** Check whether stored AI analysis is stale compared with current content/terms. */
	private function is_ai_analysis_stale( $post_id ) {
		$stored = (string) get_post_meta( $post_id, '_cto_ai_input_hash', true );
		return '' !== $stored && $stored !== CTO_AI_Service::calculate_input_hash( $post_id );
	}

	/** Process a recommendation workflow action and return the new status. */
	private function process_recommendation_action( $post_id, $key, $action ) {
		$status = get_post_meta( $post_id, '_cto_ai_recommendation_status', true );
		$status = is_array( $status ) ? $status : array();
		if ( 'ignore' === $action ) { $status[ $key ] = 'ignored'; CTO_AI_Service::log( 'recommendation_ignored', $post_id, $key ); }
		elseif ( 'reset' === $action ) { $status[ $key ] = 'open'; }
		elseif ( 'accept' === $action ) { $result = $this->accept_recommendation( $post_id, $key ); if ( is_wp_error( $result ) ) { return $result; } $status[ $key ] = 'accepted'; CTO_AI_Service::log( 'recommendation_accepted', $post_id, $key ); }
		elseif ( 'checked' === $action ) { $status[ $key ] = 'accepted'; CTO_AI_Service::log( 'internal_link_checked', $post_id, $key ); }
		else { return new WP_Error( 'cto_invalid_action', __( 'Ungültige Aktion.', 'content-taxonomy-overview' ) ); }
		update_post_meta( $post_id, '_cto_ai_recommendation_status', $status );
		return $status[ $key ];
	}

	/** Build recommendation action link. */
	private function recommendation_link( $post_id, $key, $action, $label ) {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'cto_ai_recommendation_action', 'post_id' => $post_id, 'rec_key' => $key, 'rec_action' => $action, 'redirect_to' => $this->get_current_overview_url() ), admin_url( 'admin-post.php' ) ), 'cto_ai_recommendation_' . $post_id . '_' . $key );
		return '<a class="button button-small cto-ajax-action" href="' . esc_url( $url ) . '" data-cto-ajax="recommendation" data-post-id="' . esc_attr( $post_id ) . '" data-rec-key="' . esc_attr( $key ) . '" data-rec-action="' . esc_attr( $action ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'cto_ai_recommendation_' . $post_id . '_' . $key ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/** Build current overview URL so workflow actions keep active filters. */
	private function get_current_overview_url() {
		$args = array();
		foreach ( wp_unslash( $_GET ) as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $value ) ) { $args[ sanitize_key( $key ) ] = sanitize_text_field( $value ); }
		}
		foreach ( array( 'action', '_wpnonce', 'cto_notice', 'cto_error', 'redirect_to', 'post_id', 'rec_key', 'rec_action' ) as $remove_key ) { unset( $args[ $remove_key ] ); }
		$args['page'] = 'content-taxonomy-overview';
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/** Accept one recommendation, applying taxonomy terms only for taxonomy recommendations. */
	private function accept_recommendation( $post_id, $key ) {
		$data = get_post_meta( $post_id, '_cto_ai_analysis_data', true );
		if ( ! is_array( $data ) ) { return new WP_Error( 'cto_no_ai_data', __( 'No AI data found.', 'content-taxonomy-overview' ) ); }
		foreach ( array( 'recommended_categories' => 'category', 'recommended_tags' => 'post_tag' ) as $group => $taxonomy ) {
			foreach ( (array) ( $data[ $group ] ?? array() ) as $index => $item ) {
				if ( CTO_AI_Service::recommendation_key( $group, $index, $item ) === $key ) { return $this->apply_term_recommendation( $post_id, $taxonomy, $this->get_recommendation_term_name( $item ) ); }
			}
		}
		foreach ( (array) ( $data['recommended_custom_taxonomies'] ?? array() ) as $tax_index => $tax_item ) {
			$taxonomy = sanitize_key( $tax_item['taxonomy'] ?? '' );
			foreach ( (array) ( $tax_item['terms'] ?? array() ) as $term_index => $term_item ) {
				if ( CTO_AI_Service::recommendation_key( 'recommended_custom_taxonomies', $tax_index . '_' . $term_index, $term_item ) === $key ) { return $this->apply_term_recommendation( $post_id, $taxonomy, $this->get_recommendation_term_name( $term_item ), true ); }
			}
		}
		return true;
	}

	/** Get a recommendation term name from old and new stored formats. */
	private function get_recommendation_term_name( $item ) {
		if ( is_array( $item ) ) {
			return sanitize_text_field( $item['name'] ?? $item['term'] ?? $item['title'] ?? '' );
		}
		return sanitize_text_field( (string) $item );
	}

	/** Apply term recommendation after explicit admin click. */
	private function apply_term_recommendation( $post_id, $taxonomy, $term_name, $custom = false ) {
		$settings = CTO_AI_Service::get_settings();
		$post = get_post( $post_id );
		if ( ! $post || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) { return new WP_Error( 'cto_tax_invalid', __( 'Taxonomy is not registered for this post type.', 'content-taxonomy-overview' ) ); }
		$term_name = sanitize_text_field( $term_name );
		if ( '' === $term_name ) { return new WP_Error( 'cto_empty_term', __( 'Die Empfehlung enthält keinen gültigen Begriff.', 'content-taxonomy-overview' ) ); }
		$term = term_exists( $term_name, $taxonomy );
		if ( $term ) {
			$term_id = is_array( $term ) ? absint( $term['term_id'] ) : absint( $term );
			if ( has_term( $term_id, $taxonomy, $post_id ) ) {
				CTO_AI_Service::log( 'taxonomy_already_applied', $post_id, $taxonomy . ':' . $term_name );
				return 'already_applied';
			}
		} else {
			$allowed = $custom ? ! empty( $settings['allow_create_custom_terms'] ) : ! empty( $settings['allow_create_terms'] );
			if ( ! $allowed ) { return new WP_Error( 'cto_term_missing', __( 'Term does not exist and creation is disabled.', 'content-taxonomy-overview' ) ); }
			$term = wp_insert_term( $term_name, $taxonomy );
			if ( is_wp_error( $term ) ) { return $term; }
			CTO_AI_Service::log( 'term_created', $post_id, $taxonomy . ':' . $term_name );
			$term_id = is_array( $term ) ? absint( $term['term_id'] ) : absint( $term );
		}
		$result = wp_set_object_terms( $post_id, array( $term_id ), $taxonomy, true );
		if ( is_wp_error( $result ) ) { return $result; }
		$this->invalidate_content_caches( $post_id );
		$this->analyzer->analyze_post( $post_id );
		$this->invalidate_content_caches( $post_id );
		CTO_AI_Service::log( 'taxonomy_applied', $post_id, $taxonomy . ':' . $term_name );
		return true;
	}

	/** Render one AI recommendation field.
	 *
	 * @param string       $label Field label.
	 * @param string|array $value Field value.
	 */
	private function render_ai_field( $label, $value ) {
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $key => $item ) {
				if ( is_array( $item ) ) {
					$flat[] = sanitize_text_field( $key ) . ': ' . implode( ', ', array_map( 'sanitize_text_field', $item ) );
				} else {
					$flat[] = sanitize_text_field( $item );
				}
			}
			$value = implode( ' | ', array_filter( $flat ) );
		}
		?>
		<div class="cto-ai-field"><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( '' !== (string) $value ? (string) $value : '—' ); ?></div>
		<?php
	}




	/** Check whether rule-based analysis is older than the post modification date. */
	private function is_rule_analysis_stale( $post ) {
		$analyzed_at = (string) get_post_meta( $post->ID, '_cto_analyzed_at', true );
		if ( '' === $analyzed_at || empty( $post->post_modified_gmt ) || '0000-00-00 00:00:00' === $post->post_modified_gmt ) {
			return false;
		}
		$analyzed_gmt = get_gmt_from_date( $analyzed_at );
		return strtotime( $post->post_modified_gmt ) > strtotime( $analyzed_gmt );
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
	private function render_pagination( $query, $filters, $position = 'bottom' ) {
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
				'cto_ai_status' => $filters['ai_status'],
				'cto_rec_status'=> $filters['rec_status'],
				'cto_intent'    => $filters['intent'],
				'cto_cluster'   => $filters['cluster'],
			),
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		echo '<div class="tablenav ' . esc_attr( $position ) . '"><div class="tablenav-pages">';
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
