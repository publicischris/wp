<?php
/**
 * Settings page for future AI analysis.
 *
 * @package ContentTaxonomyOverview
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings admin page.
 */
class CTO_Settings {
	/** Constructor. */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cto_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_cto_test_api', array( $this, 'test_api' ) );
	}

	/** Register submenu. */
	public function register_menu() {
		add_submenu_page( 'content-taxonomy-overview', __( 'Einstellungen', 'content-taxonomy-overview' ), __( 'Einstellungen', 'content-taxonomy-overview' ), 'manage_options', 'content-taxonomy-overview-settings', array( $this, 'render_page' ) );
	}

	/** Render settings page. */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}
		$settings = CTO_AI_Service::get_settings();
		?>
		<div class="wrap cto-wrap">
			<h1><?php esc_html_e( 'Content Taxonomy Einstellungen', 'content-taxonomy-overview' ); ?></h1>
			<?php $this->render_notices(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cto_save_settings' ); ?>
				<input type="hidden" name="action" value="cto_save_settings" />
				<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="cto_api_key">OpenAI API Key</label></th><td><input type="password" class="regular-text" id="cto_api_key" name="api_key" value="" autocomplete="off" placeholder="<?php echo esc_attr( CTO_Utils::mask_api_key( $settings['api_key'] ) ); ?>" /><p class="description"><?php esc_html_e( 'Leer lassen, um den gespeicherten Key beizubehalten. Der Key wird nie vollständig angezeigt.', 'content-taxonomy-overview' ); ?></p></td></tr>
				<tr><th scope="row"><label for="cto_model">Modellname</label></th><td><input type="text" class="regular-text" id="cto_model" name="model" value="<?php echo esc_attr( $settings['model'] ); ?>" /></td></tr>
				<tr><th scope="row">KI-Analyse aktivieren</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'], 1 ); ?> /> <?php esc_html_e( 'Aktivieren, sobald API-Daten gültig sind.', 'content-taxonomy-overview' ); ?></label></td></tr>
				<tr><th scope="row"><label for="cto_max_chars">Maximal analysierte Zeichen</label></th><td><input type="number" min="500" step="100" id="cto_max_chars" name="max_chars" value="<?php echo esc_attr( $settings['max_chars'] ); ?>" /></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Einstellungen speichern', 'content-taxonomy-overview' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cto_test_api' ); ?>
				<input type="hidden" name="action" value="cto_test_api" />
				<?php submit_button( __( 'API-Verbindung testen', 'content-taxonomy-overview' ), 'secondary' ); ?>
			</form>
			<p><strong><?php esc_html_e( 'Status:', 'content-taxonomy-overview' ); ?></strong> <?php echo CTO_AI_Service::is_configured() ? esc_html__( 'KI vorbereitet und konfiguriert.', 'content-taxonomy-overview' ) : esc_html__( 'Regelbasierter Betrieb aktiv; KI nicht konfiguriert.', 'content-taxonomy-overview' ); ?></p>
		</div>
		<?php
	}

	/** Save settings. */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}
		check_admin_referer( 'cto_save_settings' );
		CTO_AI_Service::save_settings( $_POST );
		wp_safe_redirect( add_query_arg( array( 'page' => 'content-taxonomy-overview-settings', 'cto_settings_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Test API. */
	public function test_api() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-taxonomy-overview' ) );
		}
		check_admin_referer( 'cto_test_api' );
		$result = CTO_AI_Service::test_connection();
		$notice = is_wp_error( $result ) ? 'api_failed' : 'api_ok';
		wp_safe_redirect( add_query_arg( array( 'page' => 'content-taxonomy-overview-settings', 'cto_settings_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Render notices. */
	private function render_notices() {
		$notice = isset( $_GET['cto_settings_notice'] ) ? sanitize_key( wp_unslash( $_GET['cto_settings_notice'] ) ) : '';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen gespeichert.', 'content-taxonomy-overview' ) . '</p></div>';
		} elseif ( 'api_ok' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API-Verbindung erfolgreich getestet.', 'content-taxonomy-overview' ) . '</p></div>';
		} elseif ( 'api_failed' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'API-Verbindung konnte nicht bestätigt werden.', 'content-taxonomy-overview' ) . '</p></div>';
		}
	}
}
