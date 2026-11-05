<?php
/**
 * Variables des modèles de SMS de commande.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs de {order_number}, {customer_name}, {order_total}… pour une commande.
 */
final class Order_Variables {

	/**
	 * Variables d'une commande.
	 *
	 * @param \WC_Order $order  Commande.
	 * @param string    $status Statut concerné (sans « wc- »).
	 * @return array<string, string>
	 */
	public static function for_order( \WC_Order $order, string $status ): array {
		$first_name = trim( $order->get_billing_first_name() );
		$last_name  = trim( $order->get_billing_last_name() );
		if ( '' === $first_name && '' === $last_name ) {
			$first_name = trim( $order->get_shipping_first_name() );
			$last_name  = trim( $order->get_shipping_last_name() );
		}
		$tracking = self::tracking( $order );
		$created  = $order->get_date_created();

		$variables = array(
			'{order_number}'        => (string) $order->get_order_number(),
			'{order_id}'            => (string) $order->get_id(),
			'{order_status}'        => wc_get_order_status_name( $status ),
			'{order_total}'         => self::total( $order ),
			'{order_date}'          => null !== $created ? (string) wc_format_datetime( $created ) : '',
			'{order_url}'           => $order->get_view_order_url(),
			'{payment_method}'      => $order->get_payment_method_title(),
			'{customer_name}'       => trim( $first_name . ' ' . $last_name ),
			'{customer_first_name}' => $first_name,
			'{customer_phone}'      => '' !== $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone(),
			'{shop_name}'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'{shop_url}'            => home_url( '/' ),
			'{tracking_number}'     => $tracking['number'],
			'{tracking_url}'        => $tracking['url'],
		);

		/**
		 * Variables des modèles de SMS de commande.
		 *
		 * @param array     $variables « {nom} » => valeur.
		 * @param \WC_Order $order     Commande.
		 * @param string    $status    Statut concerné.
		 */
		$variables = (array) apply_filters( 'labyrinthe_connect_order_sms_variables', $variables, $order, $status );

		return array_map( static fn( $value ): string => is_scalar( $value ) ? (string) $value : '', $variables );
	}

	/**
	 * Montant en texte brut et en ASCII : « 25.00 USD », « 15 000 CDF ».
	 *
	 * La fonction wc_price() produirait du HTML et des espaces insécables (qui feraient passer le SMS en Unicode).
	 *
	 * @param \WC_Order $order Commande.
	 */
	private static function total( \WC_Order $order ): string {
		$separators = array(
			"\u{00A0}" => ' ',
			"\u{202F}" => ' ',
		);
		$decimal    = strtr( wc_get_price_decimal_separator(), $separators );
		$thousand   = strtr( wc_get_price_thousand_separator(), $separators );
		$amount     = number_format( (float) $order->get_total(), wc_get_price_decimals(), '' !== $decimal ? $decimal : '.', $thousand );
		$total      = $amount . ' ' . $order->get_currency();

		/**
		 * Montant de la commande dans les SMS.
		 *
		 * @param string    $total Montant (« 25.00 USD »).
		 * @param \WC_Order $order Commande.
		 */
		return (string) apply_filters( 'labyrinthe_connect_order_total', $total, $order );
	}

	/**
	 * Numéro et lien de suivi (extensions WooCommerce Shipment Tracking et Advanced Shipment Tracking).
	 *
	 * @param \WC_Order $order Commande.
	 * @return array{number: string, url: string}
	 */
	private static function tracking( \WC_Order $order ): array {
		$number = '';
		$url    = '';
		$items  = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $items ) && array() !== $items ) {
			$item = end( $items );
			if ( is_array( $item ) ) {
				$number = isset( $item['tracking_number'] ) ? (string) $item['tracking_number'] : '';
				$url    = isset( $item['custom_tracking_link'] ) ? (string) $item['custom_tracking_link'] : '';
			}
		}

		/**
		 * Lien de suivi de la commande ({tracking_url}).
		 *
		 * @param string    $url   Lien ('' sans suivi).
		 * @param \WC_Order $order Commande.
		 */
		$url = (string) apply_filters( 'labyrinthe_connect_tracking_url', $url, $order );

		return array(
			'number' => $number,
			'url'    => '' !== $url ? esc_url_raw( $url ) : '',
		);
	}
}
