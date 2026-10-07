<?php
/**
 * Settings → Xtream UI Pro.
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

class XtreamPro_Settings {

	const GROUP = 'xtreampro_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_xtreampro_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_xtreampro_refresh_packages', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_xtreampro_webhook_register', array( __CLASS__, 'handle_webhook_register' ) );
		add_action( 'admin_post_xtreampro_webhook_test', array( __CLASS__, 'handle_webhook_test' ) );
		add_action( 'admin_post_xtreampro_webhook_remove', array( __CLASS__, 'handle_webhook_remove' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'Xtream UI Pro', 'xtreampro' ),
			__( 'Xtream UI Pro', 'xtreampro' ),
			'manage_options',
			'xtreampro',
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			self::GROUP,
			'xtreampro_api_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_url' ),
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			'xtreampro_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_key_value' ),
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			'xtreampro_panel_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_panel_url' ),
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			XtreamPro_Webhook::OPTION_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_webhook_secret' ),
				'default'           => '',
			)
		);
	}

	/**
	 * An empty submission keeps the stored secret.
	 *
	 * @param mixed $value Submitted secret.
	 * @return string
	 */
	public static function sanitize_webhook_secret( $value ) {
		$old   = (string) get_option( XtreamPro_Webhook::OPTION_SECRET, '' );
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value || ( defined( 'XTREAMPRO_WEBHOOK_SECRET' ) && XTREAMPRO_WEBHOOK_SECRET ) ) {
			return $old;
		}
		return $value;
	}

	/**
	 * @param mixed $value Submitted dashboard address.
	 * @return string
	 */
	public static function sanitize_panel_url( $value ) {
		if ( defined( 'XTREAMPRO_PANEL_URL' ) && XTREAMPRO_PANEL_URL ) {
			return (string) get_option( 'xtreampro_panel_url', '' );
		}
		return untrailingslashit( esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) ) );
	}

	/**
	 * @param mixed $value Submitted URL.
	 * @return string
	 */
	public static function sanitize_url( $value ) {
		if ( defined( 'XTREAMPRO_API_URL' ) && XTREAMPRO_API_URL ) {
			return (string) get_option( 'xtreampro_api_url', '' );
		}
		$url = untrailingslashit( esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) ) );
		delete_transient( XtreamPro_API::PACKAGES_TRANSIENT );
		return $url;
	}

	/**
	 * An empty submission keeps the stored key.
	 *
	 * @param mixed $value Submitted key.
	 * @return string
	 */
	public static function sanitize_key_value( $value ) {
		$old   = (string) get_option( 'xtreampro_api_key', '' );
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value || ( defined( 'XTREAMPRO_API_KEY' ) && XTREAMPRO_API_KEY ) ) {
			return $old;
		}
		delete_transient( XtreamPro_API::PACKAGES_TRANSIENT );
		return $value;
	}

	private static function result_key() {
		return 'xtreampro_result_' . get_current_user_id();
	}

	private static function back( $notice ) {
		wp_safe_redirect( add_query_arg( 'xtreampro_notice', $notice, admin_url( 'options-general.php?page=xtreampro' ) ) );
		exit;
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'xtreampro' ), 403 );
		}
		check_admin_referer( 'xtreampro_test' );

		$info = XtreamPro_API::user_info();
		if ( is_wp_error( $info ) ) {
			$result = array(
				'ok'      => false,
				'message' => $info->get_error_message(),
			);
		} else {
			$name    = '';
			$fields  = array( 'fullname', 'username' );
			foreach ( $fields as $f ) {
				if ( '' === $name && ! empty( $info[ $f ] ) ) {
					$name = (string) $info[ $f ];
				}
			}
			$credits = isset( $info['credits'] ) ? (float) $info['credits'] : 0;
			$result  = array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: reseller name, 2: credit balance */
					__( 'Connected as %1$s. Credit balance: %2$s.', 'xtreampro' ),
					$name,
					number_format_i18n( $credits, floor( $credits ) === $credits ? 0 : 2 )
				),
			);
		}
		set_transient( self::result_key(), $result, MINUTE_IN_SECONDS );
		self::back( 'test' );
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'xtreampro' ), 403 );
		}
		check_admin_referer( 'xtreampro_refresh_packages' );

		$list = XtreamPro_API::packages( true );
		if ( is_wp_error( $list ) ) {
			$result = array(
				'ok'      => false,
				'message' => $list->get_error_message(),
			);
		} else {
			$result = array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %d: number of packages */
					_n( 'Package list refreshed: %d package.', 'Package list refreshed: %d packages.', count( $list ), 'xtreampro' ),
					count( $list )
				),
			);
		}
		set_transient( self::result_key(), $result, MINUTE_IN_SECONDS );
		self::back( 'packages' );
	}

	/**
	 * Ask the panel to post events to this site and keep the secret it shows once.
	 */
	public static function handle_webhook_register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'xtreampro' ), 403 );
		}
		check_admin_referer( 'xtreampro_webhook_register' );

		$url  = XtreamPro_Webhook::url();
		$data = XtreamPro_API::create_webhook( $url, XtreamPro_Webhook::EVENTS );
		if ( is_wp_error( $data ) ) {
			$message = $data->get_error_message();
			if ( 'INVALID_REQUEST' === $data->get_error_code() ) {
				/* translators: %s: address */
				$message = sprintf( __( 'The panel refused the address %s: it delivers to https addresses only (http only when the panel runs with WEBHOOK_ALLOW_PRIVATE).', 'xtreampro' ), $url );
			}
			$result = array(
				'ok'      => false,
				'message' => $message,
			);
		} elseif ( empty( $data['id'] ) || empty( $data['secret'] ) ) {
			$result = array(
				'ok'      => false,
				'message' => __( 'The panel did not answer with a valid API response. Check the API URL.', 'xtreampro' ),
			);
		} else {
			update_option( XtreamPro_Webhook::OPTION_SECRET, sanitize_text_field( (string) $data['secret'] ), false );
			update_option( XtreamPro_Webhook::OPTION_ID, sanitize_text_field( (string) $data['id'] ), false );
			$result = array(
				'ok'      => true,
				/* translators: %s: address */
				'message' => sprintf( __( 'Webhook registered: the panel will post line events to %s.', 'xtreampro' ), $url ),
			);
		}
		set_transient( self::result_key(), $result, MINUTE_IN_SECONDS );
		self::back( 'webhook' );
	}

	public static function handle_webhook_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'xtreampro' ), 403 );
		}
		check_admin_referer( 'xtreampro_webhook_test' );

		$id = (string) get_option( XtreamPro_Webhook::OPTION_ID, '' );
		if ( '' === $id ) {
			$result = array(
				'ok'      => false,
				'message' => __( 'No webhook was registered from this site yet.', 'xtreampro' ),
			);
		} else {
			$data   = XtreamPro_API::test_webhook( $id );
			$result = is_wp_error( $data )
				? array(
					'ok'      => false,
					'message' => $data->get_error_message(),
				)
				: array(
					'ok'      => true,
					'message' => __( 'A test event was queued on the panel. It reaches this site within a few seconds.', 'xtreampro' ),
				);
		}
		set_transient( self::result_key(), $result, MINUTE_IN_SECONDS );
		self::back( 'webhook' );
	}

	public static function handle_webhook_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'xtreampro' ), 403 );
		}
		check_admin_referer( 'xtreampro_webhook_remove' );

		$id   = (string) get_option( XtreamPro_Webhook::OPTION_ID, '' );
		$data = '' === $id ? array() : XtreamPro_API::delete_webhook( $id );
		if ( is_wp_error( $data ) && 'RESOURCE_NOT_FOUND' !== $data->get_error_code() ) {
			$result = array(
				'ok'      => false,
				'message' => $data->get_error_message(),
			);
		} else {
			delete_option( XtreamPro_Webhook::OPTION_ID );
			delete_option( XtreamPro_Webhook::OPTION_SECRET );
			$result = array(
				'ok'      => true,
				'message' => __( 'Webhook removed.', 'xtreampro' ),
			);
		}
		set_transient( self::result_key(), $result, MINUTE_IN_SECONDS );
		self::back( 'webhook' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url_locked = defined( 'XTREAMPRO_API_URL' ) && XTREAMPRO_API_URL;
		$key_locked = defined( 'XTREAMPRO_API_KEY' ) && XTREAMPRO_API_KEY;
		$panel_locked = defined( 'XTREAMPRO_PANEL_URL' ) && XTREAMPRO_PANEL_URL;
		$hook_locked  = defined( 'XTREAMPRO_WEBHOOK_SECRET' ) && XTREAMPRO_WEBHOOK_SECRET;
		$hook_set     = '' !== XtreamPro_Webhook::secret();
		$key_set    = '' !== XtreamPro_API::api_key();
		$result     = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['xtreampro_notice'] ) ) {
			$result = get_transient( self::result_key() );
			delete_transient( self::result_key() );
		}
		$cached = get_transient( XtreamPro_API::PACKAGES_TRANSIENT );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Xtream UI Pro', 'xtreampro' ); ?></h1>

			<?php if ( is_array( $result ) ) : ?>
				<div class="notice <?php echo ! empty( $result['ok'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
					<p><?php echo esc_html( $result['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xtreampro_api_url"><?php echo esc_html__( 'API URL', 'xtreampro' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="xtreampro_api_url" name="xtreampro_api_url"
								value="<?php echo esc_attr( XtreamPro_API::base_url() ); ?>"
								placeholder="https://api.example.com" <?php disabled( $url_locked ); ?> />
							<p class="description">
								<?php
								echo $url_locked
									? esc_html__( 'Defined by XTREAMPRO_API_URL in wp-config.php.', 'xtreampro' )
									: esc_html__( 'Public address of the panel API, without a path. Subscribers use it as server address.', 'xtreampro' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xtreampro_api_key"><?php echo esc_html__( 'API key', 'xtreampro' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="xtreampro_api_key" name="xtreampro_api_key"
								value="" autocomplete="new-password"
								placeholder="<?php echo $key_set ? esc_attr( '••••••••••••' ) : ''; ?>" <?php disabled( $key_locked ); ?> />
							<p class="description">
								<?php
								if ( $key_locked ) {
									echo esc_html__( 'Defined by XTREAMPRO_API_KEY in wp-config.php.', 'xtreampro' );
								} elseif ( $key_set ) {
									echo esc_html__( 'A key is saved. Leave empty to keep it, or type a new one to replace it.', 'xtreampro' );
								} else {
									echo esc_html__( 'API key of a reseller account (panel → API key). Orders are charged to that reseller\'s credits.', 'xtreampro' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xtreampro_panel_url"><?php echo esc_html__( 'Panel address', 'xtreampro' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="xtreampro_panel_url" name="xtreampro_panel_url"
								value="<?php echo esc_attr( XtreamPro_API::panel_url() ); ?>"
								placeholder="https://panel.example.com" <?php disabled( $panel_locked ); ?> />
							<p class="description">
								<?php
								echo $panel_locked
									? esc_html__( 'Defined by XTREAMPRO_PANEL_URL in wp-config.php.', 'xtreampro' )
									: esc_html__( 'Optional. Address of the panel dashboard; customers who bought a sub-reseller account get address/login as their sign-in link.', 'xtreampro' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xtreampro_webhook_secret"><?php echo esc_html__( 'Webhook secret', 'xtreampro' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="xtreampro_webhook_secret" name="<?php echo esc_attr( XtreamPro_Webhook::OPTION_SECRET ); ?>"
								value="" autocomplete="new-password"
								placeholder="<?php echo $hook_set ? esc_attr( '••••••••••••' ) : ''; ?>" <?php disabled( $hook_locked ); ?> />
							<p class="description">
								<?php
								if ( $hook_locked ) {
									echo esc_html__( 'Defined by XTREAMPRO_WEBHOOK_SECRET in wp-config.php.', 'xtreampro' );
								} elseif ( $hook_set ) {
									echo esc_html__( 'A secret is saved. Leave empty to keep it. Events with another signature are refused.', 'xtreampro' );
								} else {
									echo esc_html__( 'Signs the events the panel pushes. Use the button "Register webhook" below to fill it in, or paste the secret of a webhook you created on the panel. Without it every event is refused.', 'xtreampro' );
								}
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Test connection', 'xtreampro' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="xtreampro_test" />
				<?php wp_nonce_field( 'xtreampro_test' ); ?>
				<?php submit_button( __( 'Test connection', 'xtreampro' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Webhook from the panel', 'xtreampro' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'The panel can tell this site when a line expires or is deleted there. Address the panel posts to (https only):', 'xtreampro' ); ?>
				<code><?php echo esc_html( XtreamPro_Webhook::url() ); ?></code>
			</p>
			<div>
				<?php foreach ( array( 'register' => __( 'Register webhook', 'xtreampro' ), 'test' => __( 'Send test event', 'xtreampro' ), 'remove' => __( 'Remove webhook', 'xtreampro' ) ) as $step => $label ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="xtreampro_webhook_<?php echo esc_attr( $step ); ?>" />
						<?php wp_nonce_field( 'xtreampro_webhook_' . $step ); ?>
						<?php submit_button( $label, 'secondary', 'submit', false ); ?>
					</form>
				<?php endforeach; ?>
			</div>

			<hr />
			<h2><?php echo esc_html__( 'Packages', 'xtreampro' ); ?></h2>
			<p class="description"><?php echo esc_html__( 'Packages are cached for 10 minutes. The package pickers of products and downloads, and [xtreampro_packages], only offer the packages the panel sells as a line (column "Sold as"); packages for MAG / Enigma boxes only are left out.', 'xtreampro' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="xtreampro_refresh_packages" />
				<?php wp_nonce_field( 'xtreampro_refresh_packages' ); ?>
				<?php submit_button( __( 'Refresh package list', 'xtreampro' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( is_array( $cached ) && $cached ) : ?>
				<table class="widefat striped" style="max-width:900px;margin-top:12px">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'ID', 'xtreampro' ); ?></th>
							<th><?php echo esc_html__( 'Name', 'xtreampro' ); ?></th>
							<th><?php echo esc_html__( 'Type', 'xtreampro' ); ?></th>
							<th><?php echo esc_html__( 'Duration', 'xtreampro' ); ?></th>
							<th><?php echo esc_html__( 'Connections', 'xtreampro' ); ?></th>
							<th><?php echo esc_html__( 'Sold as', 'xtreampro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $cached as $pkg ) : ?>
							<?php
							$types = array();
							if ( ! empty( $pkg['is_official'] ) ) {
								$types[] = __( 'Official', 'xtreampro' );
							}
							if ( ! empty( $pkg['is_trial'] ) ) {
								$types[] = __( 'Trial', 'xtreampro' );
							}
							$durs = array();
							if ( ! empty( $pkg['is_official'] ) ) {
								$durs[] = XtreamPro_API::format_duration( isset( $pkg['official_duration'] ) ? $pkg['official_duration'] : 0, isset( $pkg['official_duration_in'] ) ? $pkg['official_duration_in'] : '' );
							}
							if ( ! empty( $pkg['is_trial'] ) ) {
								$durs[] = XtreamPro_API::format_duration( isset( $pkg['trial_duration'] ) ? $pkg['trial_duration'] : 0, isset( $pkg['trial_duration_in'] ) ? $pkg['trial_duration_in'] : '' );
							}
							?>
							<tr>
								<td><?php echo esc_html( (string) $pkg['id'] ); ?></td>
								<td><?php echo esc_html( isset( $pkg['name'] ) ? (string) $pkg['name'] : '' ); ?></td>
								<td><?php echo esc_html( implode( ' / ', $types ) ); ?></td>
								<td><?php echo esc_html( implode( ' / ', $durs ) ); ?></td>
								<td><?php echo esc_html( isset( $pkg['max_connections'] ) ? (string) (int) $pkg['max_connections'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $pkg['sells'] ) && is_array( $pkg['sells'] ) ? implode( ' / ', array_map( 'strval', $pkg['sells'] ) ) : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr />
			<h2><?php echo esc_html__( 'Shortcodes', 'xtreampro' ); ?></h2>
			<p><code>[xtreampro_packages]</code> <code>[xtreampro_packages ids="1,2"]</code> <code>[xtreampro_packages trial="1"]</code> <code>[xtreampro_my_lines]</code> <code>[xtreampro_my_reseller]</code></p>

			<?php do_action( 'xtreampro_settings_page' ); ?>
		</div>
		<?php
	}
}
