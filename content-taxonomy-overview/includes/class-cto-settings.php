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
		$post_type_objects = function_exists( 'get_post_types' ) ? get_post_types( array( 'show_ui' => true ), 'objects' ) : array();
		unset( $post_type_objects['attachment'] );
		$enabled_post_types = isset( $settings['enabled_post_types'] ) && is_array( $settings['enabled_post_types'] ) ? $settings['enabled_post_types'] : array( 'post', 'page' );
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

				<tr><th scope="row"><?php esc_html_e( 'Zu analysierende Post Types', 'content-taxonomy-overview' ); ?></th><td class="cto-post-type-settings"><?php foreach ( $post_type_objects as $post_type => $post_type_object ) : $taxonomies = get_object_taxonomies( $post_type, 'objects' ); ?><label class="cto-post-type-option"><input type="checkbox" name="enabled_post_types[]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $enabled_post_types, true ) ); ?> /> <strong><?php echo esc_html( $post_type_object->labels->name ); ?></strong> <span class="description">(<?php echo esc_html( $post_type ); ?>)</span><br><span class="description"><?php echo esc_html__( 'Taxonomien:', 'content-taxonomy-overview' ); ?> <?php echo esc_html( ! empty( $taxonomies ) ? implode( ', ', wp_list_pluck( $taxonomies, 'label' ) ) : '—' ); ?></span></label><?php endforeach; ?><p class="description"><?php esc_html_e( 'Nur ausgewählte Post Types werden analysiert, gefiltert und an die KI übergeben.', 'content-taxonomy-overview' ); ?></p></td></tr>
				<tr><th scope="row"><label for="cto_max_chars">Maximal analysierte Zeichen</label></th><td><input type="number" min="500" step="100" id="cto_max_chars" name="max_chars" value="<?php echo esc_attr( $settings['max_chars'] ); ?>" /></td></tr>
				<tr><th scope="row">KI-Ergebnisse speichern</th><td><label><input type="checkbox" name="save_results" value="1" <?php checked( $settings['save_results'], 1 ); ?> /> <?php esc_html_e( 'KI-Ergebnisse in Post Meta speichern.', 'content-taxonomy-overview' ); ?></label></td></tr>
				<tr><th scope="row">KI-Analyse nur manuell starten</th><td><label><input type="checkbox" name="manual_only" value="1" <?php checked( $settings['manual_only'], 1 ); ?> /> <?php esc_html_e( 'Keine automatische KI-Analyse ausführen.', 'content-taxonomy-overview' ); ?></label></td></tr>
				<tr><th scope="row">KI nach regelbasierter Analyse starten</th><td><label><input type="checkbox" name="auto_after_rule" value="1" <?php checked( $settings['auto_after_rule'], 1 ); ?> /> <?php esc_html_e( 'Automatisch nach dem Speichern analysieren, wenn KI aktiviert ist. Standard: aus.', 'content-taxonomy-overview' ); ?></label></td></tr>
				<tr><th scope="row">Neue Kategorien/Tags erstellen erlauben</th><td><label><input type="checkbox" name="allow_create_terms" value="1" <?php checked( $settings['allow_create_terms'], 1 ); ?> /> <?php esc_html_e( 'Nur nach Admin-Klick neue Kategorien oder Tags aus KI-Empfehlungen erstellen.', 'content-taxonomy-overview' ); ?></label></td></tr>
				<tr><th scope="row">Neue Custom-Taxonomy-Terms erstellen erlauben</th><td><label><input type="checkbox" name="allow_create_custom_terms" value="1" <?php checked( $settings['allow_create_custom_terms'], 1 ); ?> /> <?php esc_html_e( 'Nur nach Admin-Klick neue Terms in bestehenden Custom Taxonomies erstellen.', 'content-taxonomy-overview' ); ?></label></td></tr>
				<tr><th scope="row">API Key entfernen</th><td><label><input type="checkbox" name="remove_api_key" value="1" /> <?php esc_html_e( 'Gespeicherten API Key beim Speichern entfernen.', 'content-taxonomy-overview' ); ?></label></td></tr>
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
		$args   = array( 'page' => 'content-taxonomy-overview-settings', 'cto_settings_notice' => $notice );
		if ( is_wp_error( $result ) ) {
			$args['cto_error'] = $result->get_error_message();
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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
			$error = isset( $_GET['cto_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cto_error'] ) ) : __( 'API-Verbindung konnte nicht bestätigt werden.', 'content-taxonomy-overview' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
	}
}
