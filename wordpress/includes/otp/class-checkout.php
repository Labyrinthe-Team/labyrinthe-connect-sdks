<?php
/**
 * Vérification du téléphone à la commande WooCommerce (classique et en blocs).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

use LabyrintheConnect\Phone;
use LabyrintheConnect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * La commande n'est acceptée que si le téléphone de facturation a été vérifié par SMS
 * dans cette session, ou s'il est déjà le numéro vérifié du compte connecté.
 */
final class Checkout {

	public const ORDER_META = '_labyrinthe_phone_verified';

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
	 * Branche la validation de commande.
	 */
	public function register(): void {
		if ( ! $this->settings->get( 'otp_checkout_enabled' ) || ! $this->settings->has_api_key() ) {
			return;
		}
		// Validation classique (shortcode [woocommerce_checkout]).
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render_classic' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'mark_order' ) );
		// Validation en blocs (Store API).
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'render_blocks' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_blocks' ), 10, 1 );
	}

	/**
	 * Formulaire OTP sous l'adresse de facturation (lit le champ « Téléphone »).
	 */
	public function render_classic(): void {
		echo Widget::render( Otp_Service::CONTEXT_CHECKOUT, array( 'phone_source' => '#billing_phone' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML échappé dans Widget::render().
	}

	/**
	 * Formulaire OTP au-dessus du bloc de validation de commande.
	 *
	 * @param string $content Rendu du bloc.
	 */
	public function render_blocks( $content ): string {
		return Widget::render(
			Otp_Service::CONTEXT_CHECKOUT,
			array( 'title' => __( 'Verify your phone number (use the same number in the billing address)', 'labyrinthe-connect' ) )
		) . (string) $content;
	}

	/**
	 * Validation classique.
	 *
	 * @param array<string, mixed> $data   Données de la commande.
	 * @param \WP_Error            $errors Erreurs.
	 */
	public function validate_classic( $data, $errors ): void {
		$message = $this->error_for( is_array( $data ) && isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : '', is_array( $data ) && isset( $data['billing_country'] ) ? (string) $data['billing_country'] : null );
		if ( null !== $message && $errors instanceof \WP_Error ) {
			$errors->add( 'labyrinthe_phone_not_verified', $message );
		}
	}

	/**
	 * Validation en blocs : refuse la commande avec une erreur affichée par le bloc.
	 *
	 * @param \WC_Order $order Commande en cours.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Numéro non vérifié.
	 */
	public function validate_blocks( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$message = $this->error_for( $order->get_billing_phone(), $order->get_billing_country() );
		if ( null !== $message ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'labyrinthe_phone_not_verified', esc_html( $message ), 400 );
		}
		$this->mark_order( $order );
	}

	/**
	 * Note sur la commande que le numéro a été vérifié ; le retient pour le compte connecté.
	 *
	 * @param \WC_Order $order Commande.
	 */
	public function mark_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order->update_meta_data( self::ORDER_META, 'yes' );
		$phone   = Phone::normalize( $order->get_billing_phone(), $this->settings->country_code(), $order->get_billing_country() );
		$user_id = $order->get_customer_id();
		if ( null !== $phone && $user_id > 0 && '' === Phone_Users::get( $user_id ) && $this->otp->is_verified( Otp_Service::CONTEXT_CHECKOUT, $phone ) ) {
			Phone_Users::set( $user_id, $phone );
		}
		$order->save();
	}

	/**
	 * Message d'erreur si le numéro n'est pas vérifié, null sinon.
	 *
	 * @param string      $raw     Téléphone de facturation.
	 * @param string|null $country Pays de facturation.
	 */
	private function error_for( string $raw, ?string $country ): ?string {
		$phone = '' === trim( $raw ) ? null : Phone::normalize( $raw, $this->settings->country_code(), $country );
		if ( null === $phone ) {
			return __( 'Please enter a valid phone number: it must be verified by SMS to place the order.', 'labyrinthe-connect' );
		}
		if ( $this->otp->is_verified( Otp_Service::CONTEXT_CHECKOUT, $phone ) || $this->is_known_phone_of_current_user( $phone ) ) {
			return null;
		}

		return __( 'Please verify your phone number with the code received by SMS before placing the order.', 'labyrinthe-connect' );
	}

	/**
	 * Vrai si le compte connecté a déjà ce numéro vérifié.
	 *
	 * @param string $phone Numéro normalisé.
	 */
	private function is_known_phone_of_current_user( string $phone ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$known = Phone_Users::get( $user_id );

		return '' !== $known && $known === $phone;
	}
}
