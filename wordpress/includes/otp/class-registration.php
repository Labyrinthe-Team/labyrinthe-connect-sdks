<?php
/**
 * Vérification du téléphone à l'inscription (WordPress et WooCommerce).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

use LabyrintheConnect\Phone;
use LabyrintheConnect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ajoute le champ téléphone + code au formulaire d'inscription, refuse l'inscription si
 * le numéro n'a pas été vérifié dans cette session, puis l'enregistre comme numéro de
 * connexion du compte (et numéro de facturation WooCommerce s'il est vide).
 */
final class Registration {

	public const FIELD = 'labyrinthe_phone';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Service OTP.
	 *
	 * @var Otp_Service
	 */
	private Otp_Service $otp;

	/**
	 * Constructeur.
	 *
	 * @param Settings    $settings Réglages.
	 * @param Otp_Service $otp      Service OTP.
	 */
	public function __construct( Settings $settings, Otp_Service $otp ) {
		$this->settings = $settings;
		$this->otp      = $otp;
	}

	/**
	 * Branche les formulaires d'inscription.
	 */
	public function register(): void {
		if ( ! $this->settings->get( 'otp_register_enabled' ) || ! $this->settings->has_api_key() ) {
			return;
		}
		add_action( 'register_form', array( $this, 'render' ) );
		add_filter( 'registration_errors', array( $this, 'validate' ), 10, 1 );
		add_action( 'woocommerce_register_form', array( $this, 'render' ) );
		add_filter( 'woocommerce_registration_errors', array( $this, 'validate' ), 10, 1 );
		add_action( 'user_register', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * Champ téléphone et code.
	 */
	public function render(): void {
		echo Widget::render( Otp_Service::CONTEXT_REGISTER, array( 'field_name' => self::FIELD ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML échappé dans Widget::render().
	}

	/**
	 * Refuse l'inscription sans numéro vérifié.
	 *
	 * @param \WP_Error $errors Erreurs d'inscription.
	 * @return \WP_Error
	 */
	public function validate( $errors ) {
		if ( ! $errors instanceof \WP_Error ) {
			return $errors;
		}
		$phone = $this->posted_phone();
		if ( null === $phone ) {
			$errors->add( 'labyrinthe_phone_missing', __( '<strong>Error:</strong> please enter a valid phone number.', 'labyrinthe-connect' ) );
		} elseif ( ! $this->otp->is_verified( Otp_Service::CONTEXT_REGISTER, $phone ) ) {
			$errors->add( 'labyrinthe_phone_not_verified', __( '<strong>Error:</strong> please verify your phone number with the code received by SMS.', 'labyrinthe-connect' ) );
		}

		return $errors;
	}

	/**
	 * Enregistre le numéro vérifié sur le nouveau compte.
	 *
	 * @param int $user_id Nouveau compte.
	 */
	public function save( $user_id ): void {
		$phone = $this->posted_phone();
		if ( null === $phone || ! $this->otp->is_verified( Otp_Service::CONTEXT_REGISTER, $phone ) ) {
			return;
		}
		Phone_Users::set( (int) $user_id, $phone );
		if ( class_exists( 'WooCommerce' ) && '' === (string) get_user_meta( (int) $user_id, 'billing_phone', true ) ) {
			update_user_meta( (int) $user_id, 'billing_phone', '+' . $phone );
		}
		$this->otp->forget_verification( Otp_Service::CONTEXT_REGISTER, $phone );
	}

	/**
	 * Numéro envoyé avec le formulaire, normalisé.
	 */
	private function posted_phone(): ?string {
		// Formulaires d'inscription du cœur et de WooCommerce (nonce vérifié par WooCommerce ;
		// le numéro n'est utilisé que s'il a été vérifié côté serveur dans cette session).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';

		return '' === $raw ? null : Phone::normalize( $raw, $this->settings->country_code() );
	}
}
