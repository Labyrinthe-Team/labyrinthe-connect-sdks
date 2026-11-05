<?php
/**
 * Modèles de SMS par défaut.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Modèles par défaut, traduisibles. Les traductions françaises livrées restent en
 * ASCII (sans accents) : un seul accent fait passer le SMS en Unicode (UCS-2, 70
 * caractères par segment au lieu de 160), ce qui double souvent le coût.
 */
final class Templates {

	/**
	 * Variables disponibles dans les modèles de commande, avec leur description.
	 *
	 * @return array<string, string>
	 */
	public static function order_variables(): array {
		return array(
			'{order_number}'        => __( 'Order number', 'labyrinthe-connect' ),
			'{order_id}'            => __( 'Order ID', 'labyrinthe-connect' ),
			'{order_status}'        => __( 'Order status (translated)', 'labyrinthe-connect' ),
			'{order_total}'         => __( 'Order total with currency, e.g. 25.00 USD', 'labyrinthe-connect' ),
			'{order_date}'          => __( 'Order date', 'labyrinthe-connect' ),
			'{order_url}'           => __( 'Order page in the customer account', 'labyrinthe-connect' ),
			'{payment_method}'      => __( 'Payment method', 'labyrinthe-connect' ),
			'{customer_name}'       => __( 'Customer full name', 'labyrinthe-connect' ),
			'{customer_first_name}' => __( 'Customer first name', 'labyrinthe-connect' ),
			'{customer_phone}'      => __( 'Customer phone number', 'labyrinthe-connect' ),
			'{shop_name}'           => __( 'Shop name', 'labyrinthe-connect' ),
			'{shop_url}'            => __( 'Shop address', 'labyrinthe-connect' ),
			'{tracking_number}'     => __( 'Tracking number (shipment tracking plugins)', 'labyrinthe-connect' ),
			'{tracking_url}'        => __( 'Tracking link (shipment tracking plugins)', 'labyrinthe-connect' ),
		);
	}

	/**
	 * Valeurs d'exemple pour l'aperçu dans les réglages.
	 *
	 * @return array<string, string>
	 */
	public static function sample_values(): array {
		return array(
			'{order_number}'        => '1042',
			'{order_id}'            => '1042',
			'{order_status}'        => __( 'Processing', 'labyrinthe-connect' ),
			'{order_total}'         => '25.00 USD',
			'{order_date}'          => '06/11/2026',
			'{order_url}'           => home_url( '/my-account/view-order/1042/' ),
			'{payment_method}'      => 'Mobile Money',
			'{customer_name}'       => 'Marie Kabongo',
			'{customer_first_name}' => 'Marie',
			'{customer_phone}'      => '243812345678',
			'{shop_name}'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{shop_url}'            => home_url( '/' ),
			'{tracking_number}'     => 'CD123456789',
			'{tracking_url}'        => 'https://track.example.com/CD123456789',
		);
	}

	/**
	 * Modèle par défaut d'un statut de commande.
	 *
	 * @param string $status Statut sans préfixe « wc- ».
	 */
	public static function order_status( string $status ): string {
		switch ( $status ) {
			case 'pending':
				return __( 'Hello {customer_name}, your order {order_number} at {shop_name} is awaiting payment ({order_total}).', 'labyrinthe-connect' );
			case 'on-hold':
				return __( 'Hello {customer_name}, your order {order_number} at {shop_name} is on hold. We will contact you shortly.', 'labyrinthe-connect' );
			case 'processing':
				return __( 'Hello {customer_name}, we received your order {order_number} ({order_total}). {shop_name} is preparing it.', 'labyrinthe-connect' );
			case 'completed':
				return __( 'Hello {customer_name}, your order {order_number} is complete. Thank you for shopping at {shop_name}! {tracking_url}', 'labyrinthe-connect' );
			case 'cancelled':
				return __( 'Hello {customer_name}, your order {order_number} at {shop_name} has been cancelled.', 'labyrinthe-connect' );
			case 'refunded':
				return __( 'Hello {customer_name}, your order {order_number} at {shop_name} has been refunded ({order_total}).', 'labyrinthe-connect' );
			case 'failed':
				return __( 'Hello {customer_name}, the payment of your order {order_number} at {shop_name} failed. Please try again.', 'labyrinthe-connect' );
			default:
				return __( 'Hello {customer_name}, your order {order_number} at {shop_name} is now: {order_status}.', 'labyrinthe-connect' );
		}
	}

	/**
	 * Modèle par défaut de la notification de nouvelle commande (administrateur).
	 */
	public static function admin_new_order(): string {
		return __( 'New order {order_number} on {shop_name}: {order_total} from {customer_name} ({customer_phone}).', 'labyrinthe-connect' );
	}

	/**
	 * Message par défaut du SMS de test.
	 */
	public static function test_message(): string {
		return __( 'Test message from {shop_name} sent with Labyrinthe Connect.', 'labyrinthe-connect' );
	}
}
