<?php
/**
 * Numéro de connexion par SMS dans le profil utilisateur.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Admin;

use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Phone;
use LabyrintheConnect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Seul un utilisateur ayant la capacité edit_users peut modifier ce numéro (un membre
 * ne peut pas s'attribuer le numéro d'un autre) ; les autres le voient en lecture seule.
 */
final class User_Profile {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Branche les hooks du profil.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/**
	 * Champ du profil.
	 *
	 * @param \WP_User $user Compte affiché.
	 */
	public function render( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$phone = Phone_Users::get( $user->ID );
		?>
		<h2><?php esc_html_e( 'Labyrinthe Connect', 'labyrinthe-connect' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="labyrinthe-phone"><?php esc_html_e( 'Phone number for SMS login', 'labyrinthe-connect' ); ?></label></th>
				<td>
					<?php if ( current_user_can( 'edit_users' ) ) : ?>
						<input type="tel" class="regular-text" id="labyrinthe-phone" name="<?php echo esc_attr( Phone_Users::META ); ?>" value="<?php echo esc_attr( '' !== $phone ? '+' . $phone : '' ); ?>" />
						<p class="description"><?php esc_html_e( 'Filled in automatically when the number is verified by SMS (registration, checkout). Leave empty to disable SMS login for this account.', 'labyrinthe-connect' ); ?></p>
					<?php else : ?>
						<p><?php echo '' !== $phone ? esc_html( Phone::mask( $phone ) ) : esc_html__( 'None', 'labyrinthe-connect' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Enregistre le numéro (nonce « update-user_{id} » vérifié par le cœur avant ce hook).
	 *
	 * @param int $user_id Compte modifié.
	 */
	public function save( $user_id ): void {
		$user_id = (int) $user_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par wp-admin/user-edit.php.
		if ( ! current_user_can( 'edit_users' ) || ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST[ Phone_Users::META ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- idem.
		$raw = sanitize_text_field( wp_unslash( $_POST[ Phone_Users::META ] ) );
		if ( '' === $raw ) {
			Phone_Users::set( $user_id, '' );

			return;
		}
		$phone = Phone::normalize( $raw, $this->settings->country_code() );
		if ( null !== $phone ) {
			Phone_Users::set( $user_id, $phone );
		}
	}
}
