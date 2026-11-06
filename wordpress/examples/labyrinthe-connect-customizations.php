<?php
/**
 * Plugin Name: Labyrinthe Connect customizations (example)
 * Description: Example must-use plugin for the hooks of the Labyrinthe Connect plugin. Copy it to wp-content/mu-plugins/ and adapt it.
 * Requires PHP: 8.1
 *
 * Exemple de personnalisation par les hooks du plugin : aucun appel direct aux classes internes.
 *
 * @package LabyrintheConnect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pas de SMS « en attente » pour les commandes payées à la livraison.
 *
 * @param bool      $send   True by default.
 * @param \WC_Order $order  Order.
 * @param string    $status Order status, without the "wc-" prefix.
 */
function labyrinthe_connect_example_send_order_sms( bool $send, \WC_Order $order, string $status ): bool {
	if ( 'on-hold' === $status && 'cod' === $order->get_payment_method() ) {
		return false;
	}

	return $send;
}
add_filter( 'labyrinthe_connect_send_order_sms', 'labyrinthe_connect_example_send_order_sms', 10, 3 );

/**
 * Nouvelle variable {delivery_slot} dans les modèles de SMS de commande (métadonnée de la commande).
 *
 * @param array<string, string> $variables "{name}" => value.
 * @param \WC_Order             $order     Order.
 * @return array<string, string>
 */
function labyrinthe_connect_example_order_sms_variables( array $variables, \WC_Order $order ): array {
	$slot = $order->get_meta( '_delivery_slot' );

	$variables['{delivery_slot}'] = is_string( $slot ) ? $slot : '';

	return $variables;
}
add_filter( 'labyrinthe_connect_order_sms_variables', 'labyrinthe_connect_example_order_sms_variables', 10, 2 );

/**
 * Courriel au gérant quand un SMS de commande échoue définitivement (jamais rejoué automatiquement).
 *
 * Le résultat ne contient ni le texte ni le numéro complet : uniquement le numéro masqué,
 * le code d'erreur et l'identifiant de requête à donner au support.
 *
 * @param array<string, scalar> $result Send result (success, recipient, error_code, retryable, request_id…).
 * @param array<string, mixed>  $meta   Context of the send (context, order_id…).
 */
function labyrinthe_connect_example_sms_sent( array $result, array $meta ): void {
	if ( ! empty( $result['success'] ) || ! empty( $result['retryable'] ) || 'order_status' !== ( $meta['context'] ?? '' ) ) {
		return;
	}

	$order_id = isset( $meta['order_id'] ) && is_numeric( $meta['order_id'] ) ? (int) $meta['order_id'] : 0;

	wp_mail(
		(string) get_option( 'admin_email' ),
		sprintf( 'SMS not sent for order %d', $order_id ),
		sprintf(
			"Recipient: %s\nError: %s %s\nRequest ID (for the Labyrinthe Connect support): %s",
			(string) ( $result['recipient'] ?? '' ),
			(string) ( $result['error_code'] ?? '' ),
			(string) ( $result['error_message'] ?? '' ),
			(string) ( $result['request_id'] ?? '' )
		)
	);
}
add_action( 'labyrinthe_connect_sms_sent', 'labyrinthe_connect_example_sms_sent', 10, 2 );

/**
 * Courriel quand le solde de crédits passe sous le seuil (webhook balance.low, déjà dédoublonné par le plugin).
 *
 * @param array<string, mixed> $envelope Webhook envelope { id, event, data, timestamp }.
 */
function labyrinthe_connect_example_balance_low( array $envelope ): void {
	$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();

	wp_mail(
		(string) get_option( 'admin_email' ),
		'Labyrinthe Connect: low SMS balance',
		sprintf(
			'Balance: %1$d credits (threshold %2$d). Buy credits in your Labyrinthe Connect client area.',
			isset( $data['balance'] ) && is_numeric( $data['balance'] ) ? (int) $data['balance'] : 0,
			isset( $data['threshold'] ) && is_numeric( $data['threshold'] ) ? (int) $data['threshold'] : 0
		)
	);
}
add_action( 'labyrinthe_balance_low', 'labyrinthe_connect_example_balance_low' );
