<?php
/**
 * Admin UI.
 *
 * @package SmartHybridCache
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Admin
 *
 * Provides the WordPress admin settings interface.
 *
 * @since 1.0.0
 */
class Smart_Hybrid_Cache_Admin {
	private Smart_Hybrid_Cache_Manager $manager;
	private string $page = 'smart-hybrid-cache';

	public function __construct( Smart_Hybrid_Cache_Manager $manager ) {
		$this->manager = $manager;
	}

	public function hooks(): void {
		add_action( 'admin_init', array( 'Smart_Hybrid_Cache_Settings', 'register' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_smart_hybrid_cache_action', array( $this, 'handle_action' ) );
	}

	public function menu(): void {
		if ( ! Smart_Hybrid_Cache_Settings::can_manage() ) {
			return; }
		add_options_page(
			esc_html__( 'Smart Hybrid Cache', 'smart-hybrid-cache' ),
			esc_html__( 'Smart Hybrid Cache', 'smart-hybrid-cache' ),
			'manage_options',
			$this->page,
			array( $this, 'render' )
		);
	}

	public function assets( string $hook ): void {
		if ( 'settings_page_' . $this->page !== $hook ) {
			return;
		}
		wp_enqueue_style( 'smart-hybrid-cache-admin', SMART_HYBRID_CACHE_URL . 'assets/admin.css', array(), SMART_HYBRID_CACHE_VERSION );
		wp_enqueue_script( 'smart-hybrid-cache-admin', SMART_HYBRID_CACHE_URL . 'assets/admin.js', array(), SMART_HYBRID_CACHE_VERSION, true );
	}

	public function notices(): void {
		if ( ! Smart_Hybrid_Cache_Settings::can_manage() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_' . $this->page !== $screen->id ) {
			return; }
		$error = Smart_Hybrid_Cache_Settings::get_shared_option( 'smart_hybrid_cache_dropin_error', '' );
		if ( is_string( $error ) && '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
		$options = Smart_Hybrid_Cache_Settings::get_options();
		$engine  = $options['engine'];
		if ( empty( $options['enable_dropin'] ) || 'disabled' === $engine ) {
			return;
		}
		$redis = class_exists( 'Redis' );
		$mem   = class_exists( 'Memcached' );
		if ( 'auto' === $engine && ! $redis && ! $mem ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Smart Hybrid Cache: No supported PHP cache extension is available. Install Redis or Memcached before enabling the object cache drop-in.', 'smart-hybrid-cache' ) );
			return;
		}
		if ( 'redis' === $engine && ! $redis ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Smart Hybrid Cache: Redis PHP extension is not available.', 'smart-hybrid-cache' ) );
		}
		if ( 'memcached' === $engine && ! $mem ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Smart Hybrid Cache: Memcached PHP extension is not available.', 'smart-hybrid-cache' ) );
		}
	}

	public function handle_action(): void {
		if ( ! Smart_Hybrid_Cache_Settings::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage cache settings.', 'smart-hybrid-cache' ) );
		}
		check_admin_referer( 'smart_hybrid_cache_action' );
		$action = isset( $_POST['shc_action'] ) && is_string( $_POST['shc_action'] ) ? sanitize_key( wp_unslash( $_POST['shc_action'] ) ) : '';
		$force  = isset( $_POST['force_replace'] ) && '1' === $_POST['force_replace'];
		$type   = 'updated';
		$text   = __( 'Action completed.', 'smart-hybrid-cache' );

		switch ( $action ) {
			case 'test_redis':
				$result = $this->manager->test( 'redis' );
				$type   = $result['ok'] ? 'updated' : 'error';
				$text   = $result['message'];
				break;
			case 'test_memcached':
				$result = $this->manager->test( 'memcached' );
				$type   = $result['ok'] ? 'updated' : 'error';
				$text   = $result['message'];
				break;
			case 'flush':
				$flushed = $this->manager->flush_safe();
				$text    = $flushed ? __( 'Cache flushed.', 'smart-hybrid-cache' ) : __( 'Cache flush could not be completed.', 'smart-hybrid-cache' );
				$type    = $flushed ? 'updated' : 'error';
				break;
			case 'install_dropin':
				$result = Smart_Hybrid_Cache_Plugin::set_dropin_enabled( true, $force );
				if ( is_wp_error( $result ) ) {
					$type = 'error';
					$text = $result->get_error_message();
				} else {
					$text = __( 'Object cache drop-in installed.', 'smart-hybrid-cache' );
					Smart_Hybrid_Cache_Logger::log( 'dropin_installed', $text );
				}
				break;
			case 'show_status':
				$text = __( 'Status refreshed.', 'smart-hybrid-cache' );
				break;
			case 'remove_dropin':
				$result = Smart_Hybrid_Cache_Plugin::set_dropin_enabled( false, $force );
				if ( is_wp_error( $result ) ) {
					$type = 'error';
					$text = $result->get_error_message();
				} else {
					$text = __( 'Object cache drop-in removed.', 'smart-hybrid-cache' );
					Smart_Hybrid_Cache_Logger::log( 'dropin_removed', $text );
				}
				break;
			case 'download_diagnostics':
				$snapshot = Smart_Hybrid_Cache_Diagnostics::snapshot();
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="smart-hybrid-cache-diagnostics-' . gmdate( 'Ymd-His' ) . '.json"' );
				echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				exit;
			default:
				$type = 'error';
				$text = __( 'Unknown action.', 'smart-hybrid-cache' );
		}

		$redirect = add_query_arg(
			array(
				'page'       => $this->page,
				'shc_tab'    => 'show_status' === $action ? 'monitoring' : 'actions',
				'shc_notice' => $text,
				'shc_type'   => $type,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function render(): void {
		if ( ! Smart_Hybrid_Cache_Settings::can_manage() ) {
			return;
		}
		$options = Smart_Hybrid_Cache_Settings::get_options();
		$status  = Smart_Hybrid_Cache_Health_Check::get_status( $this->manager );
		?>
<div class="wrap smart-hybrid-cache">
<div class="shc-hero">
<div>
<h1><?php esc_html_e( 'Smart Hybrid Cache', 'smart-hybrid-cache' ); ?></h1>
<p><?php esc_html_e( 'Hybrid Redis and Memcached object caching with safe drop-in management, health checks, and admin controls.', 'smart-hybrid-cache' ); ?></p>
</div>
<span class="shc-version"><?php echo esc_html( SMART_HYBRID_CACHE_VERSION ); ?></span>
</div>
		<?php settings_errors( 'smart_hybrid_cache' ); ?>
		<?php $this->render_redirect_notice(); ?>
		<?php $this->render_cache_availability_notice( $status ); ?>
		<?php $this->render_overview( $status ); ?>
		<?php $this->render_tabs(); ?>
<form method="post" action="options.php">
		<?php settings_fields( 'smart_hybrid_cache' ); ?>
<div class="shc-tab-panel" id="shc-tab-general" role="tabpanel" tabindex="0" aria-labelledby="shc-tab-button-general">
<h2><?php esc_html_e( 'Cache Engine', 'smart-hybrid-cache' ); ?></h2>
<table class="form-table" role="presentation"><tbody>
		<?php
		$this->select_row(
			'engine',
			__( 'Cache engine', 'smart-hybrid-cache' ),
			array(
				'auto'      => 'Auto',
				'redis'     => 'Redis',
				'memcached' => 'Memcached',
				'disabled'  => 'Disabled',
			),
			$options['engine']
		);
		?>
</tbody></table>
</div>

<div class="shc-tab-panel" id="shc-tab-redis" role="tabpanel" tabindex="0" aria-labelledby="shc-tab-button-redis" hidden>
<h2><?php esc_html_e( 'Redis Settings', 'smart-hybrid-cache' ); ?></h2>
<table class="form-table" role="presentation"><tbody>
		<?php $this->text_row( 'redis_host', __( 'Host', 'smart-hybrid-cache' ), $options['redis_host'] ); ?>
		<?php $this->number_row( 'redis_port', __( 'Port', 'smart-hybrid-cache' ), $options['redis_port'], 1, 65535 ); ?>
		<?php $this->password_row( 'redis_password', __( 'Password', 'smart-hybrid-cache' ), $options['redis_password'] ); ?>
		<?php $this->number_row( 'redis_database', __( 'Database index', 'smart-hybrid-cache' ), $options['redis_database'], 0, 255 ); ?>
		<?php $this->number_row( 'redis_timeout', __( 'Timeout', 'smart-hybrid-cache' ), $options['redis_timeout'], 0.1, 10, '0.1' ); ?>
		<?php $this->checkbox_row( 'redis_persistent', __( 'Persistent connection enabled', 'smart-hybrid-cache' ), $options['redis_persistent'] ); ?>
		<?php $this->checkbox_row( 'redis_tls', __( 'TLS enabled', 'smart-hybrid-cache' ), $options['redis_tls'] ); ?>
</tbody></table>
<p class="description"><?php esc_html_e( 'Leave the password empty to keep the saved value. Connection settings are stored in the database and the protected PHP drop-in. Restrict access to both.', 'smart-hybrid-cache' ); ?></p>
</div>

<div class="shc-tab-panel" id="shc-tab-memcached" role="tabpanel" tabindex="0" aria-labelledby="shc-tab-button-memcached" hidden>
<h2><?php esc_html_e( 'Memcached Settings', 'smart-hybrid-cache' ); ?></h2>
<table class="form-table" role="presentation"><tbody>
		<?php $this->text_row( 'memcached_host', __( 'Host', 'smart-hybrid-cache' ), $options['memcached_host'] ); ?>
		<?php $this->number_row( 'memcached_port', __( 'Port', 'smart-hybrid-cache' ), $options['memcached_port'], 1, 65535 ); ?>
		<?php $this->checkbox_row( 'memcached_persistent', __( 'Persistent connection enabled', 'smart-hybrid-cache' ), $options['memcached_persistent'] ); ?>
</tbody></table>
</div>

<div class="shc-tab-panel" id="shc-tab-behavior" role="tabpanel" tabindex="0" aria-labelledby="shc-tab-button-behavior" hidden>
<h2><?php esc_html_e( 'Cache Behavior', 'smart-hybrid-cache' ); ?></h2>
<table class="form-table" role="presentation"><tbody>
		<?php $this->number_row( 'default_ttl', __( 'Default TTL', 'smart-hybrid-cache' ), $options['default_ttl'], 0, YEAR_IN_SECONDS ); ?>
		<?php $this->text_row( 'key_prefix', __( 'Key prefix', 'smart-hybrid-cache' ), $options['key_prefix'] ); ?>
		<?php $this->checkbox_row( 'enable_dropin', __( 'Enable persistent object cache drop-in', 'smart-hybrid-cache' ), $options['enable_dropin'] ); ?>
		<?php $this->checkbox_row( 'flush_on_post_update', __( 'Flush cache on post update', 'smart-hybrid-cache' ), $options['flush_on_post_update'] ); ?>
		<?php $this->checkbox_row( 'flush_on_theme_switch', __( 'Flush cache on theme switch', 'smart-hybrid-cache' ), $options['flush_on_theme_switch'] ); ?>
		<?php $this->checkbox_row( 'flush_on_plugin_update', __( 'Flush cache on plugin update', 'smart-hybrid-cache' ), $options['flush_on_plugin_update'] ); ?>
		<?php $this->checkbox_row( 'cleanup_dropin_uninstall', __( 'Cleanup drop-in on uninstall', 'smart-hybrid-cache' ), $options['cleanup_dropin_uninstall'] ); ?>
		<?php $this->checkbox_row( 'cleanup_dropin_deactivate', __( 'Cleanup drop-in on deactivation', 'smart-hybrid-cache' ), $options['cleanup_dropin_deactivate'] ); ?>
		<?php $this->checkbox_row( 'enable_logging', __( 'Enable event logging', 'smart-hybrid-cache' ), $options['enable_logging'] ); ?>
		<?php $this->text_row( 'non_persistent_groups', __( 'Non-persistent groups', 'smart-hybrid-cache' ), $options['non_persistent_groups'] ); ?>
		<?php $this->text_row( 'additional_global_groups', __( 'Additional global groups', 'smart-hybrid-cache' ), $options['additional_global_groups'] ); ?>
</tbody></table>
<p class="description"><?php esc_html_e( 'Comma-separated cache group names. Non-persistent groups stay in request-scope memory only. Additional global groups are shared across multisite blogs.', 'smart-hybrid-cache' ); ?></p>
</div>
<div class="shc-settings-submit">
		<?php submit_button( __( 'Save Settings', 'smart-hybrid-cache' ) ); ?>
</div>
</form>

<div class="shc-tab-panel" id="shc-tab-actions" role="tabpanel" tabindex="0" aria-labelledby="shc-tab-button-actions" hidden>
		<?php $this->render_actions(); ?>
</div>
<div class="shc-tab-panel" id="shc-tab-monitoring" role="tabpanel" tabindex="0" aria-labelledby="shc-tab-button-monitoring" hidden>
		<?php $this->render_status( $status ); ?>
</div>
<div class="shc-tab-panel" id="shc-tab-docs" role="tabpanel" tabindex="0" aria-labelledby="shc-tab-button-docs" hidden>
		<?php $this->render_docs(); ?>
</div>
</div>
		<?php
	}

	private function render_tabs(): void {
		$tabs = array(
			'general'    => __( 'General', 'smart-hybrid-cache' ),
			'redis'      => __( 'Redis', 'smart-hybrid-cache' ),
			'memcached'  => __( 'Memcached', 'smart-hybrid-cache' ),
			'behavior'   => __( 'Behavior', 'smart-hybrid-cache' ),
			'actions'    => __( 'Actions', 'smart-hybrid-cache' ),
			'monitoring' => __( 'Monitoring', 'smart-hybrid-cache' ),
			'docs'       => __( 'Docs', 'smart-hybrid-cache' ),
		);
		?>
<nav class="nav-tab-wrapper shc-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Smart Hybrid Cache settings sections', 'smart-hybrid-cache' ); ?>">
		<?php foreach ( $tabs as $slug => $label ) : ?>
<button type="button" id="shc-tab-button-<?php echo esc_attr( $slug ); ?>" class="nav-tab<?php echo 'general' === $slug ? ' nav-tab-active' : ''; ?>" role="tab" aria-selected="<?php echo 'general' === $slug ? 'true' : 'false'; ?>" aria-controls="shc-tab-<?php echo esc_attr( $slug ); ?>" data-shc-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></button>
<?php endforeach; ?>
</nav>
		<?php
	}

	private function render_redirect_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only; nonce verified during the action that set these query args.
		if ( empty( $_GET['shc_notice'] ) || ! is_string( $_GET['shc_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['shc_type'] ) && 'error' === $_GET['shc_type'] ? 'notice-error' : 'notice-success';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( '<div class="notice %1$s is-dismissible inline"><p>%2$s</p></div>', esc_attr( $type ), esc_html( sanitize_text_field( wp_unslash( $_GET['shc_notice'] ) ) ) );
	}

	private function render_cache_availability_notice( array $status ): void {
		$dropin = $status['dropin'];
		$class  = ! empty( $dropin['available'] ) ? 'notice-info' : 'notice-warning';
		printf( '<div class="notice %1$s inline"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $dropin['message'] ) );
	}

	private function render_overview( array $status ): void {
		$cards = array(
			array(
				'label' => __( 'Object cache', 'smart-hybrid-cache' ),
				'value' => ! empty( $status['object_cache_available'] ) ? __( 'Available', 'smart-hybrid-cache' ) : __( 'Not active', 'smart-hybrid-cache' ),
				'state' => ! empty( $status['object_cache_available'] ) ? 'good' : 'warn',
			),
			array(
				'label' => __( 'Active engine', 'smart-hybrid-cache' ),
				'value' => 'none' !== $status['active_engine'] ? ucfirst( $status['active_engine'] ) : __( 'None', 'smart-hybrid-cache' ),
				'state' => 'none' !== $status['active_engine'] ? 'good' : 'muted',
			),
			array(
				'label' => __( 'Redis extension', 'smart-hybrid-cache' ),
				'value' => $status['redis_available'] ? __( 'Available', 'smart-hybrid-cache' ) : __( 'Missing', 'smart-hybrid-cache' ),
				'state' => $status['redis_available'] ? 'good' : 'muted',
			),
			array(
				'label' => __( 'Memcached extension', 'smart-hybrid-cache' ),
				'value' => $status['memcached_available'] ? __( 'Available', 'smart-hybrid-cache' ) : __( 'Missing', 'smart-hybrid-cache' ),
				'state' => $status['memcached_available'] ? 'good' : 'muted',
			),
		);
		?>
<div class="shc-overview" aria-label="<?php esc_attr_e( 'Smart Hybrid Cache overview', 'smart-hybrid-cache' ); ?>">
		<?php foreach ( $cards as $card ) : ?>
<div class="shc-overview-card shc-state-<?php echo esc_attr( $card['state'] ); ?>">
<span><?php echo esc_html( $card['label'] ); ?></span>
<strong><?php echo esc_html( $card['value'] ); ?></strong>
</div>
<?php endforeach; ?>
</div>
		<?php
	}

	private function render_actions(): void {
		$actions = array(
			'test_redis'           => array( __( 'Test Redis connection', 'smart-hybrid-cache' ), __( 'Verify saved Redis settings. Save changes before running this test.', 'smart-hybrid-cache' ) ),
			'test_memcached'       => array( __( 'Test Memcached connection', 'smart-hybrid-cache' ), __( 'Verify saved Memcached settings. Save changes before running this test.', 'smart-hybrid-cache' ) ),
			'flush'                => array( __( 'Flush this installation cache', 'smart-hybrid-cache' ), __( 'Invalidate this WordPress installation cache without clearing other applications.', 'smart-hybrid-cache' ) ),
			'install_dropin'       => array( __( 'Install object cache drop-in', 'smart-hybrid-cache' ), __( 'Enable persistent object caching when no other drop-in owns object-cache.php.', 'smart-hybrid-cache' ) ),
			'remove_dropin'        => array( __( 'Remove object cache drop-in', 'smart-hybrid-cache' ), __( 'Remove only the drop-in created by Smart Hybrid Cache unless replacement is confirmed.', 'smart-hybrid-cache' ) ),
			'show_status'          => array( __( 'Show current status', 'smart-hybrid-cache' ), __( 'Refresh dashboard status and recent events.', 'smart-hybrid-cache' ) ),
			'download_diagnostics' => array( __( 'Download diagnostics report', 'smart-hybrid-cache' ), __( 'Export a JSON report (Redis password redacted) suitable for support requests.', 'smart-hybrid-cache' ) ),
		);
		?>
<h2><?php esc_html_e( 'Admin Actions', 'smart-hybrid-cache' ); ?></h2>
<div class="shc-actions">
		<?php foreach ( $actions as $action => $details ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<div class="shc-action-copy">
<strong><?php echo esc_html( $details[0] ); ?></strong>
<span><?php echo esc_html( $details[1] ); ?></span>
</div>
			<?php wp_nonce_field( 'smart_hybrid_cache_action' ); ?>
<input type="hidden" name="action" value="smart_hybrid_cache_action" />
<input type="hidden" name="shc_action" value="<?php echo esc_attr( $action ); ?>" />
			<?php if ( in_array( $action, array( 'install_dropin', 'remove_dropin' ), true ) ) : ?>
<label><input type="checkbox" name="force_replace" value="1" /> <?php esc_html_e( 'Confirm replacement/removal of a non-plugin drop-in', 'smart-hybrid-cache' ); ?></label>
<?php endif; ?>
			<?php submit_button( $details[0], 'secondary', 'submit', false ); ?>
</form>
<?php endforeach; ?>
</div>
		<?php
	}

	private function render_status( array $status ): void {
		$monitoring = $status['monitoring'];
		$rows       = array(
			__( 'Selected engine', 'smart-hybrid-cache' )  => $status['selected_engine'],
			__( 'Active engine', 'smart-hybrid-cache' )    => $status['active_engine'],
			__( 'PHP Redis extension availability', 'smart-hybrid-cache' ) => $status['redis_available'] ? __( 'Available', 'smart-hybrid-cache' ) : __( 'Missing', 'smart-hybrid-cache' ),
			__( 'PHP Memcached extension availability', 'smart-hybrid-cache' ) => $status['memcached_available'] ? __( 'Available', 'smart-hybrid-cache' ) : __( 'Missing', 'smart-hybrid-cache' ),
			__( 'Drop-in status', 'smart-hybrid-cache' )   => $status['dropin']['exists'] ? __( 'Installed', 'smart-hybrid-cache' ) : __( 'Not installed', 'smart-hybrid-cache' ),
			__( 'Object cache availability', 'smart-hybrid-cache' ) => ! empty( $status['object_cache_available'] ) ? __( 'Available', 'smart-hybrid-cache' ) : __( 'Not active', 'smart-hybrid-cache' ),
			__( 'Connection status', 'smart-hybrid-cache' ) => $status['connection_status'],
			__( 'Last connection error', 'smart-hybrid-cache' ) => $status['last_error'] ?: __( 'None', 'smart-hybrid-cache' ),
			__( 'Cache prefix', 'smart-hybrid-cache' )     => $status['cache_prefix'],
			__( 'Current TTL', 'smart-hybrid-cache' )      => (string) $status['current_ttl'],
			__( 'Object cache file owner status', 'smart-hybrid-cache' ) => $status['dropin']['owner_label'],
			__( 'Multisite status', 'smart-hybrid-cache' ) => $status['multisite'] ? __( 'Enabled', 'smart-hybrid-cache' ) : __( 'Single site', 'smart-hybrid-cache' ),
			__( 'advanced-cache.php detected', 'smart-hybrid-cache' ) => $status['advanced_cache_exists'] ? __( 'Yes', 'smart-hybrid-cache' ) : __( 'No', 'smart-hybrid-cache' ),
		);

		$is_online    = 'none' !== $status['active_engine'];
		$hit_rate_raw = 0;
		if ( ! empty( $monitoring['raw_counters'] ) ) {
			$hits  = (int) $monitoring['raw_counters']['hits'];
			$miss  = (int) $monitoring['raw_counters']['misses'];
			$total = $hits + $miss;
			if ( $total > 0 ) {
				$hit_rate_raw = round( ( $hits / $total ) * 100, 1 );
			}
		}
		$bar_class = $hit_rate_raw >= 80 ? 'shc-bar-good' : ( $hit_rate_raw >= 50 ? 'shc-bar-warn' : 'shc-bar-bad' );
		?>
<h2><?php esc_html_e( 'Monitoring', 'smart-hybrid-cache' ); ?></h2>
<p><?php esc_html_e( 'These server totals can include other sites and applications using the same cache server.', 'smart-hybrid-cache' ); ?></p>
<div class="shc-monitoring-grid">
<div class="shc-monitoring-card <?php echo $is_online ? 'shc-mon-online' : 'shc-mon-offline'; ?>">
<span class="shc-card-label"><?php esc_html_e( 'Connection', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( (string) $monitoring['status'] ); ?></strong>
</div>
<div class="shc-monitoring-card shc-mon-neutral">
<span class="shc-card-label"><?php esc_html_e( 'Memory Used', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( (string) $monitoring['memory'] ); ?></strong>
</div>
<div class="shc-monitoring-card shc-mon-neutral">
<span class="shc-card-label"><?php esc_html_e( 'Uptime', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( (string) $monitoring['uptime'] ); ?></strong>
</div>
<div class="shc-monitoring-card <?php echo $hit_rate_raw >= 80 ? 'shc-mon-online' : ( $hit_rate_raw >= 50 ? 'shc-mon-neutral' : 'shc-mon-offline' ); ?>">
<span class="shc-card-label"><?php esc_html_e( 'Hit Rate', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( (string) $monitoring['hit_rate'] ); ?></strong>
		<?php if ( $is_online && ! empty( $monitoring['raw_counters'] ) ) : ?>
<div class="shc-progress"><div class="shc-progress-bar <?php echo esc_attr( $bar_class ); ?>" style="width:<?php echo esc_attr( (string) $hit_rate_raw ); ?>%"></div></div>
<?php endif; ?>
</div>
<div class="shc-monitoring-card shc-mon-neutral">
<span class="shc-card-label"><?php esc_html_e( 'Current Keys', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( (string) $monitoring['key_count'] ); ?></strong>
</div>
<div class="shc-monitoring-card shc-mon-neutral">
<span class="shc-card-label"><?php esc_html_e( 'Connections', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( (string) $monitoring['connections'] ); ?></strong>
</div>
		<?php if ( ! empty( $monitoring['raw_counters'] ) ) : ?>
<div class="shc-monitoring-card shc-mon-online">
<span class="shc-card-label"><?php esc_html_e( 'Cache Hits', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( number_format_i18n( (int) $monitoring['raw_counters']['hits'] ) ); ?></strong>
</div>
<div class="shc-monitoring-card shc-mon-offline">
<span class="shc-card-label"><?php esc_html_e( 'Cache Misses', 'smart-hybrid-cache' ); ?></span>
<strong><?php echo esc_html( number_format_i18n( (int) $monitoring['raw_counters']['misses'] ) ); ?></strong>
</div>
<?php endif; ?>
</div>

<h3><?php esc_html_e( 'Current Status', 'smart-hybrid-cache' ); ?></h3>
<table class="widefat striped shc-status"><tbody>
		<?php foreach ( $rows as $label => $value ) : ?>
<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
		<?php if ( ! empty( $status['stats'] ) ) : ?>
<h3><?php esc_html_e( 'Cache Statistics', 'smart-hybrid-cache' ); ?></h3>
			<?php $this->render_parsed_stats( $status['stats'], $status['active_engine'] ); ?>
<?php endif; ?>
<h3><?php esc_html_e( 'Recent Event Log', 'smart-hybrid-cache' ); ?></h3>
		<?php if ( empty( $status['log_events'] ) ) : ?>
<p><?php esc_html_e( 'No cache events have been logged yet.', 'smart-hybrid-cache' ); ?></p>
<?php else : ?>
<table class="widefat striped shc-log"><thead><tr>
<th scope="col"><?php esc_html_e( 'Time', 'smart-hybrid-cache' ); ?></th>
<th scope="col"><?php esc_html_e( 'Event', 'smart-hybrid-cache' ); ?></th>
<th scope="col"><?php esc_html_e( 'Message', 'smart-hybrid-cache' ); ?></th>
<th scope="col"><?php esc_html_e( 'Context', 'smart-hybrid-cache' ); ?></th>
</tr></thead><tbody>
	<?php foreach ( $status['log_events'] as $event ) : ?>
<tr>
<td><?php echo esc_html( (string) ( $event['time'] ?? '' ) ); ?></td>
<td><?php echo esc_html( (string) ( $event['event'] ?? '' ) ); ?></td>
<td><?php echo esc_html( (string) ( $event['message'] ?? '' ) ); ?></td>
<td><?php echo esc_html( wp_json_encode( $event['context'] ?? array() ) ); ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
		<?php
	}

	private function render_parsed_stats( array $stats, string $engine ): void {
		$sections = $this->categorize_stats( $stats, $engine );
		?>
<div class="shc-stats-sections">
		<?php foreach ( $sections as $section_label => $items ) : ?>
<div class="shc-stats-section">
<h4><?php echo esc_html( $section_label ); ?></h4>
<table><tbody>
			<?php foreach ( $items as $key => $value ) : ?>
<tr><th><?php echo esc_html( $this->humanize_stat_key( $key ) ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endforeach; ?>
</div>
		<?php
	}

	private function render_docs(): void {
		?>
<div class="shc-docs">

<h2><?php esc_html_e( 'Installation & Configuration Guide', 'smart-hybrid-cache' ); ?></h2>
<p class="shc-docs-intro"><?php esc_html_e( 'This guide covers installing and configuring Redis and Memcached on a Linux VPS, enabling the required PHP extensions, and connecting them to the Smart Hybrid Cache plugin.', 'smart-hybrid-cache' ); ?></p>

<div class="shc-docs-toc">
<h3><?php esc_html_e( 'Table of Contents', 'smart-hybrid-cache' ); ?></h3>
<ol>
<li><a href="#shc-doc-prerequisites"><?php esc_html_e( 'Prerequisites', 'smart-hybrid-cache' ); ?></a></li>
<li><a href="#shc-doc-redis-install"><?php esc_html_e( 'Installing Redis on a VPS', 'smart-hybrid-cache' ); ?></a></li>
<li><a href="#shc-doc-redis-php"><?php esc_html_e( 'Configuring the PHP Redis Extension', 'smart-hybrid-cache' ); ?></a></li>
<li><a href="#shc-doc-memcached-install"><?php esc_html_e( 'Installing Memcached on a VPS', 'smart-hybrid-cache' ); ?></a></li>
<li><a href="#shc-doc-memcached-php"><?php esc_html_e( 'Configuring the PHP Memcached Extension', 'smart-hybrid-cache' ); ?></a></li>
<li><a href="#shc-doc-security"><?php esc_html_e( 'Security Best Practices', 'smart-hybrid-cache' ); ?></a></li>
<li><a href="#shc-doc-plugin-config"><?php esc_html_e( 'Plugin Configuration', 'smart-hybrid-cache' ); ?></a></li>
<li><a href="#shc-doc-troubleshooting"><?php esc_html_e( 'Troubleshooting', 'smart-hybrid-cache' ); ?></a></li>
</ol>
</div>

<!-- Prerequisites -->
<div class="shc-docs-section" id="shc-doc-prerequisites">
<h3><?php esc_html_e( '1. Prerequisites', 'smart-hybrid-cache' ); ?></h3>
<ul>
<li><?php esc_html_e( 'A Linux-based VPS (Ubuntu 20.04+, Debian 11+, CentOS/RHEL 8+, or AlmaLinux 8+).', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Root or sudo access to the server.', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'PHP 8.0 or later installed with a supported SAPI (php-fpm or mod_php).', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'WordPress 6.0 or later.', 'smart-hybrid-cache' ); ?></li>
</ul>
</div>

<!-- Redis Installation -->
<div class="shc-docs-section" id="shc-doc-redis-install">
<h3><?php esc_html_e( '2. Installing Redis on a VPS', 'smart-hybrid-cache' ); ?></h3>

<h4><?php esc_html_e( 'Ubuntu / Debian', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo apt update
sudo apt install redis-server -y</code></pre>
<p><?php esc_html_e( 'Enable and start the service:', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo systemctl enable redis-server
sudo systemctl start redis-server</code></pre>

<h4><?php esc_html_e( 'CentOS / RHEL / AlmaLinux', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo dnf install redis -y</code></pre>
<p><?php esc_html_e( 'Enable and start the service:', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo systemctl enable redis
sudo systemctl start redis</code></pre>

<h4><?php esc_html_e( 'Verify Installation', 'smart-hybrid-cache' ); ?></h4>
<pre><code>redis-cli ping
# Expected response: PONG</code></pre>

<h4><?php esc_html_e( 'Basic Redis Configuration', 'smart-hybrid-cache' ); ?></h4>
<p><?php esc_html_e( 'Edit the Redis configuration file:', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo nano /etc/redis/redis.conf</code></pre>
<p><?php esc_html_e( 'Recommended settings for WordPress object caching:', 'smart-hybrid-cache' ); ?></p>
<pre><code># Bind to localhost only (security)
bind 127.0.0.1 ::1

# Set a maximum memory limit
maxmemory 256mb

# Eviction policy for cache workloads
maxmemory-policy allkeys-lru

# Set a password (strongly recommended)
requirepass your_secure_password_here

# Disable persistence for pure cache usage (optional)
save ""
appendonly no</code></pre>
<p><?php esc_html_e( 'Restart Redis after making changes:', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo systemctl restart redis-server</code></pre>
</div>

<!-- Redis PHP Extension -->
<div class="shc-docs-section" id="shc-doc-redis-php">
<h3><?php esc_html_e( '3. Configuring the PHP Redis Extension', 'smart-hybrid-cache' ); ?></h3>

<h4><?php esc_html_e( 'Ubuntu / Debian', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo apt install php-redis -y</code></pre>
<p><?php esc_html_e( 'For a specific PHP version (e.g., PHP 8.2):', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo apt install php8.2-redis -y</code></pre>

<h4><?php esc_html_e( 'CentOS / RHEL / AlmaLinux (Remi repository)', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo dnf install php-pecl-redis -y</code></pre>

<h4><?php esc_html_e( 'Installing via PECL (any distribution)', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo pecl install redis
echo "extension=redis.so" | sudo tee /etc/php/8.2/mods-available/redis.ini
sudo phpenmod redis</code></pre>

<h4><?php esc_html_e( 'Restart PHP-FPM', 'smart-hybrid-cache' ); ?></h4>
<pre><code># Ubuntu/Debian
sudo systemctl restart php8.2-fpm

# CentOS/RHEL
sudo systemctl restart php-fpm</code></pre>

<h4><?php esc_html_e( 'Verify the Extension', 'smart-hybrid-cache' ); ?></h4>
<pre><code>php -m | grep redis
# Expected output: redis</code></pre>
</div>

<!-- Memcached Installation -->
<div class="shc-docs-section" id="shc-doc-memcached-install">
<h3><?php esc_html_e( '4. Installing Memcached on a VPS', 'smart-hybrid-cache' ); ?></h3>

<h4><?php esc_html_e( 'Ubuntu / Debian', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo apt update
sudo apt install memcached libmemcached-tools -y</code></pre>
<p><?php esc_html_e( 'Enable and start the service:', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo systemctl enable memcached
sudo systemctl start memcached</code></pre>

<h4><?php esc_html_e( 'CentOS / RHEL / AlmaLinux', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo dnf install memcached -y</code></pre>
<p><?php esc_html_e( 'Enable and start the service:', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo systemctl enable memcached
sudo systemctl start memcached</code></pre>

<h4><?php esc_html_e( 'Verify Installation', 'smart-hybrid-cache' ); ?></h4>
<pre><code>echo "stats" | nc localhost 11211
# Should return server statistics</code></pre>

<h4><?php esc_html_e( 'Basic Memcached Configuration', 'smart-hybrid-cache' ); ?></h4>
<p><?php esc_html_e( 'Edit the Memcached configuration file:', 'smart-hybrid-cache' ); ?></p>
<pre><code># Ubuntu/Debian
sudo nano /etc/memcached.conf

# CentOS/RHEL
sudo nano /etc/sysconfig/memcached</code></pre>
<p><?php esc_html_e( 'Recommended settings (Ubuntu/Debian format):', 'smart-hybrid-cache' ); ?></p>
<pre><code># Listen on localhost only
-l 127.0.0.1

# Maximum memory in megabytes
-m 256

# Maximum simultaneous connections
-c 1024

# Run as memcache user
-u memcache

# Port
-p 11211</code></pre>
<p><?php esc_html_e( 'Restart Memcached after making changes:', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo systemctl restart memcached</code></pre>
</div>

<!-- Memcached PHP Extension -->
<div class="shc-docs-section" id="shc-doc-memcached-php">
<h3><?php esc_html_e( '5. Configuring the PHP Memcached Extension', 'smart-hybrid-cache' ); ?></h3>
<p class="shc-docs-note"><strong><?php esc_html_e( 'Important:', 'smart-hybrid-cache' ); ?></strong> <?php esc_html_e( 'This plugin requires the "php-memcached" extension (libmemcached-based), not the older "php-memcache" extension.', 'smart-hybrid-cache' ); ?></p>

<h4><?php esc_html_e( 'Ubuntu / Debian', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo apt install php-memcached -y</code></pre>
<p><?php esc_html_e( 'For a specific PHP version (e.g., PHP 8.2):', 'smart-hybrid-cache' ); ?></p>
<pre><code>sudo apt install php8.2-memcached -y</code></pre>

<h4><?php esc_html_e( 'CentOS / RHEL / AlmaLinux (Remi repository)', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo dnf install php-pecl-memcached -y</code></pre>

<h4><?php esc_html_e( 'Installing via PECL (any distribution)', 'smart-hybrid-cache' ); ?></h4>
<pre><code>sudo pecl install memcached
echo "extension=memcached.so" | sudo tee /etc/php/8.2/mods-available/memcached.ini
sudo phpenmod memcached</code></pre>

<h4><?php esc_html_e( 'Restart PHP-FPM', 'smart-hybrid-cache' ); ?></h4>
<pre><code># Ubuntu/Debian
sudo systemctl restart php8.2-fpm

# CentOS/RHEL
sudo systemctl restart php-fpm</code></pre>

<h4><?php esc_html_e( 'Verify the Extension', 'smart-hybrid-cache' ); ?></h4>
<pre><code>php -m | grep memcached
# Expected output: memcached</code></pre>
</div>

<!-- Security -->
<div class="shc-docs-section" id="shc-doc-security">
<h3><?php esc_html_e( '6. Security Best Practices', 'smart-hybrid-cache' ); ?></h3>
<ul>
<li><strong><?php esc_html_e( 'Bind to localhost:', 'smart-hybrid-cache' ); ?></strong> <?php esc_html_e( 'Never expose Redis or Memcached to the public internet. Always bind to 127.0.0.1.', 'smart-hybrid-cache' ); ?></li>
<li><strong><?php esc_html_e( 'Use authentication:', 'smart-hybrid-cache' ); ?></strong> <?php esc_html_e( 'Set a strong password for Redis using the "requirepass" directive.', 'smart-hybrid-cache' ); ?></li>
<li><strong><?php esc_html_e( 'Firewall rules:', 'smart-hybrid-cache' ); ?></strong> <?php esc_html_e( 'Block external access to ports 6379 (Redis) and 11211 (Memcached) using ufw or firewalld.', 'smart-hybrid-cache' ); ?></li>
<li><strong><?php esc_html_e( 'Disable dangerous commands:', 'smart-hybrid-cache' ); ?></strong> <?php esc_html_e( 'In Redis, rename or disable commands like FLUSHALL, CONFIG, and DEBUG in production.', 'smart-hybrid-cache' ); ?></li>
<li><strong><?php esc_html_e( 'TLS encryption:', 'smart-hybrid-cache' ); ?></strong> <?php esc_html_e( 'For remote connections, enable TLS in Redis 6+ and configure the plugin TLS option accordingly.', 'smart-hybrid-cache' ); ?></li>
<li><strong><?php esc_html_e( 'Regular updates:', 'smart-hybrid-cache' ); ?></strong> <?php esc_html_e( 'Keep Redis, Memcached, and PHP extensions up to date with security patches.', 'smart-hybrid-cache' ); ?></li>
</ul>
<h4><?php esc_html_e( 'Firewall Examples', 'smart-hybrid-cache' ); ?></h4>
<pre><code># UFW (Ubuntu/Debian)
sudo ufw deny 6379
sudo ufw deny 11211

# Firewalld (CentOS/RHEL)
sudo firewall-cmd --permanent --remove-port=6379/tcp
sudo firewall-cmd --permanent --remove-port=11211/tcp
sudo firewall-cmd --reload</code></pre>
</div>

<!-- Plugin Configuration -->
<div class="shc-docs-section" id="shc-doc-plugin-config">
<h3><?php esc_html_e( '7. Plugin Configuration', 'smart-hybrid-cache' ); ?></h3>
<p><?php esc_html_e( 'Once Redis or Memcached and the corresponding PHP extension are installed, configure the plugin:', 'smart-hybrid-cache' ); ?></p>
<ol>
<li><?php esc_html_e( 'Go to the General tab and select your preferred cache engine (Auto, Redis, or Memcached).', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Configure connection details in the Redis or Memcached tab (host, port, and password if applicable).', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'In the Behavior tab, enable "Persistent object cache drop-in" to activate the object cache.', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Use the Actions tab to test connectivity and install the drop-in file.', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Verify everything is working in the Monitoring tab.', 'smart-hybrid-cache' ); ?></li>
</ol>
<h4><?php esc_html_e( 'Recommended Plugin Settings', 'smart-hybrid-cache' ); ?></h4>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e( 'Setting', 'smart-hybrid-cache' ); ?></th><th><?php esc_html_e( 'Recommended Value', 'smart-hybrid-cache' ); ?></th><th><?php esc_html_e( 'Notes', 'smart-hybrid-cache' ); ?></th></tr></thead>
<tbody>
<tr><td><?php esc_html_e( 'Cache Engine', 'smart-hybrid-cache' ); ?></td><td>Auto</td><td><?php esc_html_e( 'Selects an available engine when the drop-in is installed. Save settings to select again.', 'smart-hybrid-cache' ); ?></td></tr>
<tr><td><?php esc_html_e( 'Default TTL', 'smart-hybrid-cache' ); ?></td><td>3600</td><td><?php esc_html_e( 'One hour; adjust based on content update frequency.', 'smart-hybrid-cache' ); ?></td></tr>
<tr><td><?php esc_html_e( 'Key Prefix', 'smart-hybrid-cache' ); ?></td><td>shc_</td><td><?php esc_html_e( 'Prevents key collisions in shared environments.', 'smart-hybrid-cache' ); ?></td></tr>
<tr><td><?php esc_html_e( 'Flush on Post Update', 'smart-hybrid-cache' ); ?></td><td><?php esc_html_e( 'Enabled', 'smart-hybrid-cache' ); ?></td><td><?php esc_html_e( 'Ensures content freshness after edits.', 'smart-hybrid-cache' ); ?></td></tr>
</tbody>
</table>
</div>

<!-- Troubleshooting -->
<div class="shc-docs-section" id="shc-doc-troubleshooting">
<h3><?php esc_html_e( '8. Troubleshooting', 'smart-hybrid-cache' ); ?></h3>

<h4><?php esc_html_e( 'PHP extension not detected', 'smart-hybrid-cache' ); ?></h4>
<p><?php esc_html_e( 'If the plugin reports a missing extension after installation:', 'smart-hybrid-cache' ); ?></p>
<pre><code># Check loaded modules
php -m | grep -i redis
php -m | grep -i memcached

# Verify the .ini file is loaded
php --ini | grep redis
php --ini | grep memcached

# Ensure PHP-FPM was restarted (not just CLI PHP)
sudo systemctl restart php8.2-fpm</code></pre>

<h4><?php esc_html_e( 'Connection refused errors', 'smart-hybrid-cache' ); ?></h4>
<ul>
<li><?php esc_html_e( 'Verify the service is running: systemctl status redis-server (or memcached).', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Check the bind address and port in the configuration file.', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Ensure no firewall rule is blocking localhost connections.', 'smart-hybrid-cache' ); ?></li>
</ul>

<h4><?php esc_html_e( 'Authentication errors (Redis)', 'smart-hybrid-cache' ); ?></h4>
<ul>
<li><?php esc_html_e( 'Confirm the password in the plugin matches the "requirepass" value in redis.conf.', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Test manually: redis-cli -a your_password ping', 'smart-hybrid-cache' ); ?></li>
</ul>

<h4><?php esc_html_e( 'High memory usage', 'smart-hybrid-cache' ); ?></h4>
<ul>
<li><?php esc_html_e( 'Set appropriate maxmemory limits in Redis/Memcached configuration.', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Use the "allkeys-lru" eviction policy for Redis to automatically remove least-recently-used keys.', 'smart-hybrid-cache' ); ?></li>
<li><?php esc_html_e( 'Monitor memory usage in the plugin Monitoring tab.', 'smart-hybrid-cache' ); ?></li>
</ul>

<h4><?php esc_html_e( 'Drop-in conflicts', 'smart-hybrid-cache' ); ?></h4>
<p><?php esc_html_e( 'If another plugin has already installed an object-cache.php drop-in, use the "Confirm replacement" checkbox in the Actions tab to replace it, or manually remove the existing wp-content/object-cache.php file before installing.', 'smart-hybrid-cache' ); ?></p>
</div>

</div>
		<?php
	}

	private function categorize_stats( array $stats, string $engine ): array {
		if ( 'redis' === $engine ) {
			return $this->categorize_redis_stats( $stats );
		}
		if ( 'memcached' === $engine ) {
			return $this->categorize_memcached_stats( $stats );
		}
		return array( __( 'Raw stats', 'smart-hybrid-cache' ) => $stats );
	}

	private function categorize_redis_stats( array $stats ): array {
		$categories = array(
			__( 'Server', 'smart-hybrid-cache' )      => array( 'redis_version', 'redis_mode', 'os', 'tcp_port', 'uptime_in_seconds', 'uptime_in_days', 'process_id', 'run_id', 'config_file' ),
			__( 'Memory', 'smart-hybrid-cache' )      => array( 'used_memory', 'used_memory_human', 'used_memory_peak', 'used_memory_peak_human', 'used_memory_rss', 'used_memory_rss_human', 'maxmemory', 'maxmemory_human', 'maxmemory_policy', 'mem_fragmentation_ratio' ),
			__( 'Clients', 'smart-hybrid-cache' )     => array( 'connected_clients', 'blocked_clients', 'tracking_clients', 'clients_in_timeout_table' ),
			__( 'Performance', 'smart-hybrid-cache' ) => array( 'keyspace_hits', 'keyspace_misses', 'total_commands_processed', 'total_connections_received', 'instantaneous_ops_per_sec', 'rejected_connections', 'expired_keys', 'evicted_keys' ),
			__( 'Persistence', 'smart-hybrid-cache' ) => array( 'rdb_changes_since_last_save', 'rdb_last_save_time', 'rdb_last_bgsave_status', 'aof_enabled', 'aof_last_bgrewrite_status' ),
			__( 'Replication', 'smart-hybrid-cache' ) => array( 'role', 'connected_slaves', 'repl_backlog_active' ),
		);

		$sections = array();
		$used     = array();

		foreach ( $categories as $label => $keys ) {
			$items = array();
			foreach ( $keys as $key ) {
				if ( isset( $stats[ $key ] ) && ! is_array( $stats[ $key ] ) ) {
					$items[ $key ] = $stats[ $key ];
					$used[ $key ]  = true;
				}
			}
			if ( ! empty( $items ) ) {
				$sections[ $label ] = $items;
			}
		}

		// Keyspace databases.
		$keyspace = array();
		foreach ( $stats as $key => $value ) {
			if ( str_starts_with( $key, 'db' ) && ! isset( $used[ $key ] ) ) {
				$keyspace[ $key ] = is_array( $value ) ? implode( ', ', array_map( fn( $k, $v ) => "$k=$v", array_keys( $value ), $value ) ) : (string) $value;
				$used[ $key ]     = true;
			}
		}
		if ( ! empty( $keyspace ) ) {
			$sections[ __( 'Keyspace', 'smart-hybrid-cache' ) ] = $keyspace;
		}

		// Remaining.
		$other = array();
		foreach ( $stats as $key => $value ) {
			if ( ! isset( $used[ $key ] ) && ! is_array( $value ) ) {
				$other[ $key ] = $value;
			}
		}
		if ( ! empty( $other ) ) {
			$sections[ __( 'Other', 'smart-hybrid-cache' ) ] = $other;
		}

		return $sections;
	}

	private function categorize_memcached_stats( array $stats ): array {
		// Memcached stats are per-server arrays.
		$server_stats = is_array( reset( $stats ) ) ? reset( $stats ) : $stats;
		if ( ! is_array( $server_stats ) ) {
			return array( __( 'Raw stats', 'smart-hybrid-cache' ) => $stats );
		}

		$categories = array(
			__( 'Server', 'smart-hybrid-cache' )      => array( 'version', 'pid', 'uptime', 'time', 'pointer_size', 'libevent' ),
			__( 'Memory', 'smart-hybrid-cache' )      => array( 'bytes', 'limit_maxbytes', 'bytes_read', 'bytes_written' ),
			__( 'Connections', 'smart-hybrid-cache' ) => array( 'curr_connections', 'total_connections', 'connection_structures', 'threads' ),
			__( 'Performance', 'smart-hybrid-cache' ) => array( 'cmd_get', 'cmd_set', 'get_hits', 'get_misses', 'delete_hits', 'delete_misses', 'incr_hits', 'incr_misses', 'decr_hits', 'decr_misses', 'evictions', 'reclaimed' ),
			__( 'Items', 'smart-hybrid-cache' )       => array( 'curr_items', 'total_items' ),
		);

		$sections = array();
		$used     = array();

		foreach ( $categories as $label => $keys ) {
			$items = array();
			foreach ( $keys as $key ) {
				if ( isset( $server_stats[ $key ] ) ) {
					$items[ $key ] = $server_stats[ $key ];
					$used[ $key ]  = true;
				}
			}
			if ( ! empty( $items ) ) {
				$sections[ $label ] = $items;
			}
		}

		$other = array();
		foreach ( $server_stats as $key => $value ) {
			if ( ! isset( $used[ $key ] ) && ! is_array( $value ) ) {
				$other[ $key ] = $value;
			}
		}
		if ( ! empty( $other ) ) {
			$sections[ __( 'Other', 'smart-hybrid-cache' ) ] = $other;
		}

		return $sections;
	}

	private function humanize_stat_key( string $key ): string {
		return ucfirst( str_replace( array( '_', '-' ), ' ', $key ) );
	}

	private function field_name( string $key ): string {
		return SMART_HYBRID_CACHE_OPTION . '[' . $key . ']';
	}

	private function text_row( string $key, string $label, mixed $value ): void {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input id="%1$s" class="regular-text" type="text" name="%3$s" value="%4$s" /></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $this->field_name( $key ) ), esc_attr( (string) $value ) );
	}

	private function password_row( string $key, string $label, mixed $value ): void {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input id="%1$s" class="regular-text" type="password" autocomplete="new-password" name="%3$s" value="%4$s" /></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $this->field_name( $key ) ), esc_attr( '' ) );
		$this->checkbox_row( 'clear_redis_password', __( 'Clear saved Redis password', 'smart-hybrid-cache' ), false );
	}

	private function number_row( string $key, string $label, mixed $value, float $min, float $max, string $step = '1' ): void {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input id="%1$s" type="number" min="%3$s" max="%4$s" step="%5$s" name="%6$s" value="%7$s" /></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( (string) $min ), esc_attr( (string) $max ), esc_attr( $step ), esc_attr( $this->field_name( $key ) ), esc_attr( (string) $value ) );
	}

	private function checkbox_row( string $key, string $label, mixed $checked ): void {
		printf( '<tr><th scope="row">%1$s</th><td><label><input type="hidden" name="%2$s" value="0" /><input type="checkbox" name="%2$s" value="1" %3$s /> %4$s</label></td></tr>', esc_html( $label ), esc_attr( $this->field_name( $key ) ), checked( (bool) $checked, true, false ), esc_html__( 'Enabled', 'smart-hybrid-cache' ) );
	}

	private function select_row( string $key, string $label, array $choices, string $selected ): void {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s">', esc_attr( $key ), esc_html( $label ), esc_attr( $this->field_name( $key ) ) );
		foreach ( $choices as $value => $choice_label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $choice_label ) );
		}
		echo '</select></td></tr>';
	}
}
