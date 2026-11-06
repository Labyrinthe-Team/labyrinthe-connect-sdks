<?php
/**
 * Page « Labyrinthe Connect > Réglages ».
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Admin;

use LabyrintheConnect\Sender_Ids;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Templates;
use LabyrintheConnect\Webhook\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Rendu des onglets Général, WooCommerce et OTP. Le formulaire est envoyé à options.php
 * (Settings API : nonce, capacité, puis Settings::sanitize()). Les secrets ne sont
 * jamais réaffichés : seul un indice (préfixe et 4 derniers caractères) l'est.
 */
final class Settings_Page {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sender IDs.
	 *
	 * @var Sender_Ids
	 */
	private Sender_Ids $sender_ids;

	/**
	 * Constructeur.
	 *
	 * @param Settings   $settings   Réglages.
	 * @param Sender_Ids $sender_ids Sender IDs.
	 */
	public function __construct( Settings $settings, Sender_Ids $sender_ids ) {
		$this->settings   = $settings;
		$this->sender_ids = $sender_ids;
	}

	/**
	 * Onglets disponibles.
	 *
	 * @return array<string, string>
	 */
	private function tabs(): array {
		$tabs = array( Settings::TAB_GENERAL => __( 'General', 'labyrinthe-connect' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$tabs[ Settings::TAB_WOOCOMMERCE ] = __( 'WooCommerce', 'labyrinthe-connect' );
		}
		$tabs[ Settings::TAB_OTP ] = __( 'OTP (verification and login)', 'labyrinthe-connect' );

		return $tabs;
	}

	/**
	 * Affiche la page.
	 */
	public function render(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'labyrinthe-connect' ), 403 );
		}

		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple choix d'onglet.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : Settings::TAB_GENERAL;
		$tab = isset( $tabs[ $tab ] ) ? $tab : Settings::TAB_GENERAL;
		$all = $this->settings->all();
		?>
		<div class="wrap labyrinthe-connect">
			<h1>
				<?php esc_html_e( 'Labyrinthe Connect', 'labyrinthe-connect' ); ?>
				<?php if ( $this->settings->has_api_key() ) : ?>
					<span class="labyrinthe-badge labyrinthe-badge--<?php echo $this->settings->is_test_mode() ? 'test' : 'live'; ?>">
						<?php echo $this->settings->is_test_mode() ? esc_html__( 'Test mode', 'labyrinthe-connect' ) : esc_html__( 'Live mode', 'labyrinthe-connect' ); ?>
					</span>
				<?php endif; ?>
			</h1>

			<?php settings_errors( Settings::OPTION ); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=' . Admin::SLUG ) ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( 'labyrinthe_connect' ); ?>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>" />
				<?php
				if ( Settings::TAB_WOOCOMMERCE === $tab ) {
					$this->render_woocommerce( $all );
				} elseif ( Settings::TAB_OTP === $tab ) {
					$this->render_otp( $all );
				} else {
					$this->render_general( $all );
				}
				submit_button();
				?>
			</form>

			<?php if ( Settings::TAB_GENERAL === $tab ) : ?>
				<?php $this->render_tools(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Onglet Général.
	 *
	 * @param array<string, mixed> $all Réglages.
	 */
	private function render_general( array $all ): void {
		$source = $this->settings->api_key_source();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="labyrinthe-api-key"><?php esc_html_e( 'API key', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<?php if ( 'constant' === $source ) : ?>
						<p><code><?php echo esc_html( $this->settings->api_key_hint() ); ?></code> — <?php esc_html_e( 'defined in wp-config.php (LABYRINTHE_CONNECT_API_KEY).', 'labyrinthe-connect' ); ?></p>
					<?php else : ?>
						<input type="password" class="regular-text" id="labyrinthe-api-key" name="<?php echo esc_attr( $this->name( 'api_key_new' ) ); ?>" value="" autocomplete="new-password" spellcheck="false"
							placeholder="<?php echo esc_attr( 'option' === $source ? __( 'Leave empty to keep the current key', 'labyrinthe-connect' ) : 'lsk_test_…' ); ?>" />
						<?php if ( 'option' === $source ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: API key hint, e.g. lsk_test_…abcd. */
									esc_html__( 'Current key: %s (stored encrypted).', 'labyrinthe-connect' ),
									'<code>' . esc_html( $this->settings->api_key_hint() ) . '</code>'
								);
								?>
								<label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'api_key_clear' ) ); ?>" value="1" /> <?php esc_html_e( 'Remove the key', 'labyrinthe-connect' ); ?></label>
							</p>
						<?php endif; ?>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Create it in your client area (Developers > API keys). A lsk_test_ key uses the sandbox: nothing is sent and no credit is used. Use a lsk_live_ key in production.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-sender"><?php esc_html_e( 'Sender', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<?php $this->render_sender_select( (string) $all['sender'] ); ?>
					<button type="button" class="button labyrinthe-refresh-senders"<?php disabled( ! $this->settings->has_api_key() ); ?>><?php esc_html_e( 'Refresh', 'labyrinthe-connect' ); ?></button>
					<p class="description"><?php esc_html_e( 'Approved Sender IDs of your account (name shown on the phone). Request new ones in your client area.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-country-code"><?php esc_html_e( 'Default country code', 'labyrinthe-connect' ); ?></label></th>
				<td>
					+<input type="text" class="small-text" id="labyrinthe-country-code" name="<?php echo esc_attr( $this->name( 'country_code' ) ); ?>" value="<?php echo esc_attr( (string) $all['country_code'] ); ?>" inputmode="numeric" maxlength="3" />
					<p class="description"><?php esc_html_e( 'Added to numbers typed in local format (0812345678 becomes 243812345678). 243 for the DR Congo. For WooCommerce orders, the country of the billing address is used first.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Accents', 'labyrinthe-connect' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'strip_accents' ) ); ?>" value="1"<?php checked( ! empty( $all['strip_accents'] ) ); ?> /> <?php esc_html_e( 'Remove accents and typographic quotes before sending (é → e, « → ")', 'labyrinthe-connect' ); ?></label>
					<p class="description"><?php esc_html_e( 'A single accent switches the SMS to Unicode: 70 characters per SMS instead of 160, so the cost is often doubled. Without accents, the SMS stays in GSM-7.', 'labyrinthe-connect' ); ?> <?php esc_html_e( 'The accents are removed by Labyrinthe Connect when sending, for order SMS, test SMS and OTP codes alike.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-webhook-url"><?php esc_html_e( 'Webhook URL', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<input type="text" class="large-text code" id="labyrinthe-webhook-url" value="<?php echo esc_attr( Controller::url() ); ?>" readonly />
					<button type="button" class="button labyrinthe-copy" data-target="labyrinthe-webhook-url"><?php esc_html_e( 'Copy', 'labyrinthe-connect' ); ?></button>
					<p class="description"><?php esc_html_e( 'Add this URL in your client area (Developers > Webhooks) to receive delivery reports: the SMS log and the order notes are then updated.', 'labyrinthe-connect' ); ?></p>
					<?php $ping = (int) get_option( Controller::LAST_PING_OPTION, 0 ); ?>
					<?php if ( $ping > 0 ) : ?>
						<p class="description">
							<?php
							/* translators: %s: date and time. */
							printf( esc_html__( 'Last test event received: %s.', 'labyrinthe-connect' ), esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ping ) ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-webhook-secret"><?php esc_html_e( 'Webhook secret', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<?php if ( $this->settings->webhook_secret_from_constant() ) : ?>
						<p><?php esc_html_e( 'Defined in wp-config.php (LABYRINTHE_CONNECT_WEBHOOK_SECRET).', 'labyrinthe-connect' ); ?></p>
					<?php else : ?>
						<input type="password" class="regular-text" id="labyrinthe-webhook-secret" name="<?php echo esc_attr( $this->name( 'webhook_secret_new' ) ); ?>" value="" autocomplete="new-password" spellcheck="false"
							placeholder="<?php echo esc_attr( '' !== $this->settings->webhook_secret() ? __( 'Leave empty to keep the current secret', 'labyrinthe-connect' ) : '' ); ?>" />
						<?php if ( '' !== $this->settings->webhook_secret() ) : ?>
							<p class="description">
								<?php esc_html_e( 'A secret is saved (stored encrypted).', 'labyrinthe-connect' ); ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'webhook_secret_clear' ) ); ?>" value="1" /> <?php esc_html_e( 'Remove the secret', 'labyrinthe-connect' ); ?></label>
							</p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Shown in your client area when you add the webhook. Every delivery is checked with it (HMAC-SHA256 signature).', 'labyrinthe-connect' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-retention"><?php esc_html_e( 'SMS log retention', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="labyrinthe-retention" min="0" max="3650" name="<?php echo esc_attr( $this->name( 'log_retention_days' ) ); ?>" value="<?php echo esc_attr( (string) $all['log_retention_days'] ); ?>" />
					<?php esc_html_e( 'days (0: keep forever)', 'labyrinthe-connect' ); ?>
					<p class="description"><?php esc_html_e( 'Older lines are deleted every day. Phone numbers are always stored masked.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Liste déroulante des Sender IDs approuvés (lus une fois par heure, en administration seulement).
	 *
	 * @param string $current Valeur actuelle.
	 */
	private function render_sender_select( string $current ): void {
		$senders = $this->sender_ids->cached();
		$error   = '';
		if ( null === $senders && $this->settings->has_api_key() ) {
			try {
				$senders = $this->sender_ids->refresh();
			} catch ( \Throwable $e ) {
				$senders = array();
				$error   = __( 'The list of Sender IDs could not be loaded. Use "Test the connection" below to check the API key.', 'labyrinthe-connect' );
			}
		}
		$values = array_map( static fn( array $sender ): string => $sender['value'], $senders ?? array() );
		?>
		<select id="labyrinthe-sender" name="<?php echo esc_attr( $this->name( 'sender' ) ); ?>">
			<option value=""><?php esc_html_e( 'Default sender of the platform', 'labyrinthe-connect' ); ?></option>
			<?php foreach ( $values as $value ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $current, $value ); ?>><?php echo esc_html( $value ); ?></option>
			<?php endforeach; ?>
			<?php if ( '' !== $current && ! in_array( $current, $values, true ) ) : ?>
				<option value="<?php echo esc_attr( $current ); ?>" selected>
					<?php
					/* translators: %s: Sender ID. */
					echo esc_html( sprintf( __( '%s (not in the approved list)', 'labyrinthe-connect' ), $current ) );
					?>
				</option>
			<?php endif; ?>
		</select>
		<?php if ( '' !== $error ) : ?>
			<p class="description labyrinthe-error"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Outils : test de connexion et SMS de test (AJAX, hors formulaire de réglages).
	 */
	private function render_tools(): void {
		$disabled = ! $this->settings->has_api_key();
		?>
		<h2><?php esc_html_e( 'Tools', 'labyrinthe-connect' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection', 'labyrinthe-connect' ); ?></th>
				<td>
					<button type="button" class="button labyrinthe-test-connection"<?php disabled( $disabled ); ?>><?php esc_html_e( 'Test the connection', 'labyrinthe-connect' ); ?></button>
					<p class="labyrinthe-result" data-result="connection" role="status" aria-live="polite"></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-test-phone"><?php esc_html_e( 'Test SMS', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<p><input type="tel" class="regular-text" id="labyrinthe-test-phone" placeholder="0812345678" autocomplete="off" /></p>
					<p><textarea class="large-text labyrinthe-sms-template" id="labyrinthe-test-message" rows="2" data-counter="labyrinthe-test-counter"><?php echo esc_textarea( Templates::test_message() ); ?></textarea></p>
					<p class="description labyrinthe-counter" id="labyrinthe-test-counter"></p>
					<button type="button" class="button button-secondary labyrinthe-send-test"<?php disabled( $disabled ); ?>><?php esc_html_e( 'Send a test SMS', 'labyrinthe-connect' ); ?></button>
					<p class="labyrinthe-result" data-result="test-sms" role="status" aria-live="polite"></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Onglet WooCommerce.
	 *
	 * @param array<string, mixed> $all Réglages.
	 */
	private function render_woocommerce( array $all ): void {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		unset( $statuses['wc-checkout-draft'] );
		?>
		<h2><?php esc_html_e( 'SMS to the customer when the order status changes', 'labyrinthe-connect' ); ?></h2>
		<p><?php esc_html_e( 'Sent in the background (Action Scheduler): checkout is never slowed down. Each SMS is noted in the order, and never sent twice for the same status.', 'labyrinthe-connect' ); ?></p>
		<table class="widefat striped labyrinthe-statuses">
			<thead>
				<tr>
					<th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Enabled', 'labyrinthe-connect' ); ?></span></th>
					<th scope="col"><?php esc_html_e( 'Status', 'labyrinthe-connect' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'labyrinthe-connect' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $statuses as $key => $label ) : ?>
					<?php
					$slug    = sanitize_key( 0 === strpos( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key );
					$setting = $this->settings->order_status( $slug );
					$id      = 'labyrinthe-status-' . $slug;
					?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" id="<?php echo esc_attr( $id ); ?>-enabled" name="<?php echo esc_attr( $this->name( 'wc_statuses' ) . '[' . $slug . '][enabled]' ); ?>" value="1"<?php checked( $setting['enabled'] ); ?> />
						</th>
						<td><label for="<?php echo esc_attr( $id ); ?>-enabled"><strong><?php echo esc_html( (string) $label ); ?></strong></label></td>
						<td>
							<textarea class="large-text labyrinthe-sms-template" rows="2" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $this->name( 'wc_statuses' ) . '[' . $slug . '][template]' ); ?>" data-counter="<?php echo esc_attr( $id ); ?>-counter" aria-label="<?php echo esc_attr( (string) $label ); ?>"><?php echo esc_textarea( $setting['template'] ); ?></textarea>
							<p class="description labyrinthe-counter" id="<?php echo esc_attr( $id ); ?>-counter"></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php $this->render_variables(); ?>

		<h2><?php esc_html_e( 'SMS to the shop manager for each new order', 'labyrinthe-connect' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Notification', 'labyrinthe-connect' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'wc_admin_enabled' ) ); ?>" value="1"<?php checked( ! empty( $all['wc_admin_enabled'] ) ); ?> /> <?php esc_html_e( 'Send an SMS for each new order', 'labyrinthe-connect' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-admin-phones"><?php esc_html_e( 'Phone numbers', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="labyrinthe-admin-phones" name="<?php echo esc_attr( $this->name( 'wc_admin_phones' ) ); ?>" value="<?php echo esc_attr( (string) $all['wc_admin_phones'] ); ?>" placeholder="0812345678, 0991234567" />
					<p class="description"><?php esc_html_e( 'Separate several numbers with commas.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-admin-template"><?php esc_html_e( 'Message', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<textarea class="large-text labyrinthe-sms-template" rows="2" id="labyrinthe-admin-template" name="<?php echo esc_attr( $this->name( 'wc_admin_template' ) ); ?>" data-counter="labyrinthe-admin-counter"><?php echo esc_textarea( $this->settings->admin_template() ); ?></textarea>
					<p class="description labyrinthe-counter" id="labyrinthe-admin-counter"></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Liste des variables des modèles.
	 */
	private function render_variables(): void {
		?>
		<details class="labyrinthe-variables">
			<summary><?php esc_html_e( 'Available variables', 'labyrinthe-connect' ); ?></summary>
			<ul>
				<?php foreach ( Templates::order_variables() as $variable => $description ) : ?>
					<li><code><?php echo esc_html( $variable ); ?></code> — <?php echo esc_html( $description ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'Values with accents (customer names, translated statuses) can switch the SMS to Unicode: enable "Remove accents" in the General tab to avoid it.', 'labyrinthe-connect' ); ?></p>
		</details>
		<?php
	}

	/**
	 * Onglet OTP.
	 *
	 * @param array<string, mixed> $all Réglages.
	 */
	private function render_otp( array $all ): void {
		?>
		<p><?php esc_html_e( 'One-time codes are sent and checked by Labyrinthe Connect (same price as an SMS). The code length, validity and number of attempts come from the OTP settings of your client area.', 'labyrinthe-connect' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Login by SMS', 'labyrinthe-connect' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'otp_login_enabled' ) ); ?>" value="1"<?php checked( ! empty( $all['otp_login_enabled'] ) ); ?> /> <?php esc_html_e( 'Allow users to log in with a code received by SMS', 'labyrinthe-connect' ); ?></label><br />
						<label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'otp_login_form' ) ); ?>" value="1"<?php checked( ! empty( $all['otp_login_form'] ) ); ?> /> <?php esc_html_e( 'Add it to the WordPress login form and to the WooCommerce "My account" form', 'labyrinthe-connect' ); ?></label><br />
						<label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'otp_allow_admins' ) ); ?>" value="1"<?php checked( ! empty( $all['otp_allow_admins'] ) ); ?> /> <?php esc_html_e( 'Also allow administrators (not recommended)', 'labyrinthe-connect' ); ?></label>
					</fieldset>
					<p class="description">
						<?php
						printf(
							/* translators: 1: shortcode, 2: block name. */
							esc_html__( 'Anywhere else: shortcode %1$s or the block "%2$s". An account logs in with the phone number verified at registration or at checkout, or set by an administrator in the user profile.', 'labyrinthe-connect' ),
							'<code>[labyrinthe_otp_login redirect="/my-account/"]</code>',
							esc_html__( 'SMS login (Labyrinthe Connect)', 'labyrinthe-connect' )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Registration', 'labyrinthe-connect' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'otp_register_enabled' ) ); ?>" value="1"<?php checked( ! empty( $all['otp_register_enabled'] ) ); ?> /> <?php esc_html_e( 'Require a phone number verified by SMS to create an account (WordPress and WooCommerce)', 'labyrinthe-connect' ); ?></label></td>
			</tr>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Checkout', 'labyrinthe-connect' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'otp_checkout_enabled' ) ); ?>" value="1"<?php checked( ! empty( $all['otp_checkout_enabled'] ) ); ?> /> <?php esc_html_e( 'Require the billing phone to be verified by SMS to place an order', 'labyrinthe-connect' ); ?></label></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="labyrinthe-otp-template"><?php esc_html_e( 'Message', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<textarea class="large-text labyrinthe-sms-template" rows="2" id="labyrinthe-otp-template" name="<?php echo esc_attr( $this->name( 'otp_template' ) ); ?>" data-counter="labyrinthe-otp-counter" placeholder="<?php echo esc_attr__( 'Your code is {code}. Valid {minutes} min.', 'labyrinthe-connect' ); ?>"><?php echo esc_textarea( (string) $all['otp_template'] ); ?></textarea>
					<p class="description labyrinthe-counter" id="labyrinthe-otp-counter"></p>
					<p class="description"><?php esc_html_e( 'Optional: must contain {code}; {app_name} and {minutes} are also available. Empty: the message of your account OTP settings.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic code filling', 'labyrinthe-connect' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'otp_web_otp' ) ); ?>" value="1"<?php checked( ! empty( $all['otp_web_otp'] ) ); ?> /> <?php esc_html_e( 'Let the browser fill in the code from the SMS (Web OTP, Chrome on Android)', 'labyrinthe-connect' ); ?></label>
					<p>
						<label for="labyrinthe-otp-web-otp-domain"><?php esc_html_e( 'Domain', 'labyrinthe-connect' ); ?></label>
						<input type="text" class="regular-text code" id="labyrinthe-otp-web-otp-domain" name="<?php echo esc_attr( $this->name( 'otp_web_otp_domain' ) ); ?>" value="<?php echo esc_attr( (string) $all['otp_web_otp_domain'] ); ?>" placeholder="<?php echo esc_attr( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" spellcheck="false" />
					</p>
					<p class="description"><?php esc_html_e( 'Adds a last line "@domain #code" to the SMS. Empty: the domain of this site. It must be the domain of the page where the code is typed; a local address (localhost, IP address) turns it off.', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="labyrinthe-otp-limit"><?php esc_html_e( 'Limit per visitor', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<input type="number" class="small-text" id="labyrinthe-otp-limit" min="1" max="100" name="<?php echo esc_attr( $this->name( 'otp_ip_send_limit' ) ); ?>" value="<?php echo esc_attr( (string) $all['otp_ip_send_limit'] ); ?>" />
					<?php esc_html_e( 'codes per hour and per IP address', 'labyrinthe-connect' ); ?>
					<p class="description"><?php esc_html_e( 'Protects your credits against robots, in addition to the limits of Labyrinthe Connect (per number and per account).', 'labyrinthe-connect' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Nom d'un champ de l'option.
	 *
	 * @param string $key Réglage.
	 */
	private function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}
}
