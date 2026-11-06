<?php
/**
 * Endpoint REST des webhooks : /wp-json/labyrinthe/v1/webhook.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Webhook;

use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Phone;
use LabyrintheConnect\Settings;
use LabyrintheConnect\WooCommerce\Order_Notifications;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\SignatureVerificationException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Webhook\Event\MessageStatusChanged;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Webhook\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Reçoit les événements de Labyrinthe Connect.
 *
 * 1. vérifie la signature X-Signature sur le corps brut (temps constant, SDK) et
 *    l'horodatage signé (anti-rejeu, 5 minutes) ;
 * 2. dédoublonne sur `id` (un événement déjà traité répond 200 sans rien refaire) ;
 * 3. met à jour le journal des SMS (statut, erreur) et les notes de commande (la commande est
 *    retrouvée par le journal, sinon par la référence « order-<id> » passée à l'envoi), puis
 *    déclenche les actions WordPress : labyrinthe_webhook_received,
 *    labyrinthe_message_status_changed, labyrinthe_otp_verified, labyrinthe_otp_failed,
 *    labyrinthe_campaign_completed, labyrinthe_balance_low, labyrinthe_webhook_test_ping.
 *
 * Les actions reçoivent l'enveloppe décodée (tableau { id, event, data, timestamp }),
 * indépendante des classes internes du SDK.
 */
final class Controller {

	public const NAMESPACE = 'labyrinthe/v1';
	public const ROUTE     = '/webhook';

	public const BALANCE_LOW_OPTION = 'labyrinthe_connect_balance_low';
	public const LAST_PING_OPTION   = 'labyrinthe_connect_last_ping';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Journal.
	 *
	 * @var Message_Log
	 */
	private Message_Log $log;

	/**
	 * Événements reçus.
	 *
	 * @var Event_Store
	 */
	private Event_Store $events;

	/**
	 * Constructeur.
	 *
	 * @param Settings    $settings Réglages.
	 * @param Message_Log $log      Journal.
	 * @param Event_Store $events   Événements reçus.
	 */
	public function __construct( Settings $settings, Message_Log $log, Event_Store $events ) {
		$this->settings = $settings;
		$this->log      = $log;
		$this->events   = $events;
	}

	/**
	 * URL à déclarer dans l'espace client (Développeurs > Webhooks).
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * Enregistre la route (rest_api_init).
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Public : l'authentification est la signature HMAC du corps, vérifiée dans handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Traite une livraison.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$secret = $this->settings->webhook_secret();
		if ( '' === $secret ) {
			// 503 : Labyrinthe Connect réessaiera, le temps de configurer le secret.
			return new \WP_Error( 'labyrinthe_webhook_not_configured', __( 'The webhook secret is not configured.', 'labyrinthe-connect' ), array( 'status' => 503 ) );
		}

		/**
		 * Tolérance de l'horodatage signé, en secondes (anti-rejeu).
		 *
		 * @param int $tolerance 300 par défaut.
		 */
		$tolerance = (int) apply_filters( 'labyrinthe_connect_webhook_tolerance', Webhook::DEFAULT_TOLERANCE );

		try {
			$event = Webhook::constructEvent( $request->get_body(), (string) $request->get_header( 'x_signature' ), $secret, max( 0, $tolerance ) );
		} catch ( SignatureVerificationException $e ) {
			return new \WP_Error( 'labyrinthe_invalid_signature', __( 'Invalid webhook signature.', 'labyrinthe-connect' ), array( 'status' => 400 ) );
		} catch ( InvalidWebhookPayloadException $e ) {
			return new \WP_Error( 'labyrinthe_invalid_payload', __( 'Invalid webhook payload.', 'labyrinthe-connect' ), array( 'status' => 400 ) );
		}

		$envelope = $event->raw();

		if ( ! $this->events->claim( $event->id(), $event->type() ) ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		try {
			$this->dispatch( $event->type(), $envelope, $event instanceof MessageStatusChanged ? $event : null );
		} catch ( \Throwable $e ) {
			// Le traitement a échoué : l'événement sera retraité à la tentative suivante.
			$this->events->release( $event->id() );

			return new \WP_Error( 'labyrinthe_webhook_failed', __( 'The webhook could not be processed.', 'labyrinthe-connect' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Traite un événement vérifié et nouveau.
	 *
	 * @param string                    $type     Type d'événement.
	 * @param array<mixed>              $envelope Enveloppe décodée.
	 * @param MessageStatusChanged|null $message  Événement typé (message.status_changed).
	 */
	private function dispatch( string $type, array $envelope, ?MessageStatusChanged $message ): void {
		/**
		 * Tout événement webhook vérifié (signature valide) et non encore reçu.
		 *
		 * @param array $envelope { id, event, data, timestamp }.
		 */
		do_action( 'labyrinthe_webhook_received', $envelope );

		switch ( $type ) {
			case 'message.status_changed':
				$row = null;
				if ( null !== $message ) {
					// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du SDK.
					$row = $this->log->update_status( $message->messageId, $message->status->value, $message->errorCode, $message->errorMessage );
					$this->add_delivery_note(
						null !== $row ? (int) $row['order_id'] : 0,
						$message->reference,
						null !== $row ? $row['recipient'] : Phone::mask( $message->recipient ),
						$message->status->value,
						(string) $message->errorCode,
						(string) $message->errorMessage,
						$row
					);
					// phpcs:enable
				}
				/**
				 * Le statut d'un message du compte a changé.
				 *
				 * @param array      $envelope { id, event, data: { message_id, recipient, status, provider_message_id, campaign_id, reference, error_code, error_message }, timestamp }.
				 * @param array|null $row      Ligne du journal du plugin (null : message non envoyé par ce site).
				 */
				do_action( 'labyrinthe_message_status_changed', $envelope, $row );
				break;

			case 'otp.verified':
				/**
				 * Un code OTP du compte a été vérifié (API ou console OTP de l'espace client). Jamais le code.
				 *
				 * @param array $envelope Enveloppe (data : otp_id, phone, status « verified », reason null, verified_at).
				 */
				do_action( 'labyrinthe_otp_verified', $envelope );
				break;

			case 'otp.failed':
				/**
				 * Une vérification OTP du compte a échoué définitivement (tentatives épuisées ou code expiré).
				 *
				 * @param array $envelope Enveloppe (data : otp_id, phone, status « failed » ou « expired », reason, verified_at null).
				 */
				do_action( 'labyrinthe_otp_failed', $envelope );
				break;

			case 'campaign.completed':
				/**
				 * Une campagne de l'espace client est terminée.
				 *
				 * @param array $envelope Enveloppe (data : campaign_id, name, recipients_count, messages_by_status, credits_charged, completed_at).
				 */
				do_action( 'labyrinthe_campaign_completed', $envelope );
				break;

			case 'balance.low':
				$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
				update_option(
					self::BALANCE_LOW_OPTION,
					array(
						'balance'   => (int) ( $data['balance'] ?? 0 ),
						'threshold' => (int) ( $data['threshold'] ?? 0 ),
						'time'      => time(),
					),
					false
				);
				/**
				 * Le solde de crédits est passé sous le seuil du compte.
				 *
				 * @param array $envelope Enveloppe (data : balance, threshold).
				 */
				do_action( 'labyrinthe_balance_low', $envelope );
				break;

			case 'test.ping':
				update_option( self::LAST_PING_OPTION, time(), false );
				/**
				 * Événement de test (bouton « Tester » de l'espace client).
				 *
				 * @param array $envelope Enveloppe.
				 */
				do_action( 'labyrinthe_webhook_test_ping', $envelope );
				break;
		}
	}

	/**
	 * Note de commande quand un SMS de commande atteint un statut définitif.
	 *
	 * @param int                        $order_id      Commande du journal (0 : inconnue).
	 * @param string|null                $reference     Référence passée à l'envoi (order-<id>).
	 * @param string                     $recipient     Numéro masqué.
	 * @param string                     $status        Statut.
	 * @param string                     $error_code    Code d'erreur ('' : aucun).
	 * @param string                     $error_message Message d'erreur.
	 * @param array<string, string>|null $row           Ligne du journal (null : message absent du journal).
	 */
	private function add_delivery_note( int $order_id, ?string $reference, string $recipient, string $status, string $error_code, string $error_message, ?array $row ): void {
		if ( $order_id <= 0 ) {
			$order_id = Order_Notifications::order_id_from_reference( $reference );
		}
		if ( $order_id <= 0 || ! in_array( $status, Message_Log::FINAL_STATUSES, true ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$context = null !== $row ? $row : array(
			'order_id'  => (string) $order_id,
			'recipient' => $recipient,
			'status'    => $status,
		);

		/**
		 * Ajouter une note de commande à la remise (ou à l'échec définitif) d'un SMS.
		 *
		 * @param bool  $enabled Vrai par défaut.
		 * @param array $row     Ligne du journal (ou order_id, recipient et status si le message n'y est pas).
		 */
		if ( ! apply_filters( 'labyrinthe_connect_delivery_order_notes', true, $context ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 'delivered' === $status ) {
			/* translators: %s: masked phone number. */
			$note = sprintf( __( 'Labyrinthe Connect: SMS delivered to %s.', 'labyrinthe-connect' ), $recipient );
		} else {
			/* translators: 1: masked phone number, 2: delivery status (failed, undeliverable…). */
			$note = sprintf( __( 'Labyrinthe Connect: SMS to %1$s not delivered (%2$s).', 'labyrinthe-connect' ), $recipient, $status );
			if ( '' !== $error_code ) {
				/* translators: 1: error code, 2: error message. */
				$note .= ' ' . sprintf( __( 'Error %1$s: %2$s', 'labyrinthe-connect' ), sanitize_text_field( $error_code ), sanitize_text_field( $error_message ) );
			}
		}

		$order->add_order_note( $note );
	}
}
