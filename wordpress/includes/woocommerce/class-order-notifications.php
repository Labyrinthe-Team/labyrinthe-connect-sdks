<?php
/**
 * SMS automatiques des commandes WooCommerce.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\WooCommerce;

use LabyrintheConnect\Send_Result;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Sms_Sender;
use LabyrintheConnect\Sms_Text;

defined( 'ABSPATH' ) || exit;

/**
 * SMS au client à chaque changement de statut (modèle par statut), et à
 * l'administrateur pour chaque nouvelle commande.
 *
 * - Asynchrone : le hook WooCommerce ne fait que planifier une action (Action Scheduler) ;
 *   aucun appel réseau pendant le paiement ou le changement de statut.
 * - Aucun doublon : l'action est unique (commande + statut), le SMS déjà envoyé est noté
 *   dans la commande, et l'Idempotency-Key (site + commande + statut) empêche l'API
 *   d'envoyer deux fois, y compris lors des nouveaux essais après une erreur réseau.
 * - Une note de commande indique chaque envoi (ou l'échec, avec le code d'erreur).
 */
final class Order_Notifications {

	public const HOOK_CUSTOMER = 'labyrinthe_connect_order_sms';
	public const HOOK_ADMIN    = 'labyrinthe_connect_admin_order_sms';
	public const GROUP         = 'labyrinthe-connect';

	/** Métadonnée de commande : SMS déjà envoyés (clé => identifiant de message). */
	public const SENT_META = '_labyrinthe_sms_sent';

	/** Tentatives au plus (la première comprise) pour une erreur temporaire. */
	public const MAX_ATTEMPTS = 4;

	/** Préfixe de la référence des SMS d'une commande (order-1042). */
	public const REFERENCE_PREFIX = 'order-';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Envoi des SMS.
	 *
	 * @var Sms_Sender
	 */
	private Sms_Sender $sender;

	/**
	 * Constructeur.
	 *
	 * @param Settings   $settings Réglages.
	 * @param Sms_Sender $sender   Envoi des SMS.
	 */
	public function __construct( Settings $settings, Sms_Sender $sender ) {
		$this->settings = $settings;
		$this->sender   = $sender;
	}

	/**
	 * Branche les hooks WooCommerce et Action Scheduler.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_placed' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_placed' ), 10, 1 );
		add_action( self::HOOK_CUSTOMER, array( $this, 'send_customer_sms' ), 10, 3 );
		add_action( self::HOOK_ADMIN, array( $this, 'send_admin_sms' ), 10, 2 );
	}

	/**
	 * Changement de statut : planifie le SMS du client si ce statut est activé.
	 *
	 * @param int|string $order_id Commande.
	 * @param string     $from     Ancien statut.
	 * @param string     $to       Nouveau statut.
	 */
	public function on_status_changed( $order_id, $from, $to ): void {
		$status = self::status_slug( (string) $to );
		if ( ! $this->settings->has_api_key() || ! $this->settings->order_status( $status )['enabled'] ) {
			return;
		}
		$this->enqueue( self::HOOK_CUSTOMER, array( (int) $order_id, $status, 1 ) );
	}

	/**
	 * Commande passée (validation classique ou en blocs) : SMS à l'administrateur, et
	 * SMS « en attente de paiement » (WooCommerce ne signale pas ce statut initial).
	 *
	 * @param int|\WC_Order $order Commande ou identifiant.
	 */
	public function on_order_placed( $order ): void {
		$order_id = $order instanceof \WC_Order ? $order->get_id() : (int) $order;
		if ( $order_id <= 0 || ! $this->settings->has_api_key() ) {
			return;
		}
		if ( $this->settings->get( 'wc_admin_enabled' ) && array() !== $this->settings->admin_phones() ) {
			$this->enqueue( self::HOOK_ADMIN, array( $order_id, 1 ) );
		}
		if ( $this->settings->order_status( 'pending' )['enabled'] ) {
			$this->enqueue( self::HOOK_CUSTOMER, array( $order_id, 'pending', 1 ) );
		}
	}

	/**
	 * Action planifiée : SMS du client pour un statut.
	 *
	 * @param int|string $order_id Commande.
	 * @param string     $status   Statut (sans « wc- »).
	 * @param int|string $attempt  Numéro de tentative.
	 */
	public function send_customer_sms( $order_id, $status, $attempt = 1 ): void {
		$order   = wc_get_order( (int) $order_id );
		$status  = self::status_slug( (string) $status );
		$attempt = max( 1, (int) $attempt );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$key  = 'status:' . $status;
		$sent = self::sent( $order );
		if ( isset( $sent[ $key ] ) ) {
			return; // Déjà envoyé : le hook s'est déclenché deux fois.
		}
		// « En attente de paiement » : seulement si la commande l'est toujours.
		if ( 'pending' === $status && ! $order->has_status( 'pending' ) ) {
			return;
		}

		$setting = $this->settings->order_status( $status );
		if ( ! $setting['enabled'] ) {
			return;
		}

		/**
		 * Envoyer (ou non) le SMS d'un statut de commande.
		 *
		 * @param bool      $send   Vrai par défaut.
		 * @param \WC_Order $order  Commande.
		 * @param string    $status Statut.
		 */
		if ( ! apply_filters( 'labyrinthe_connect_send_order_sms', true, $order, $status ) ) {
			return;
		}

		$phone = '' !== $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone();
		if ( '' === trim( $phone ) ) {
			$order->add_order_note( __( 'Labyrinthe Connect: no SMS sent, the order has no phone number.', 'labyrinthe-connect' ) );
			return;
		}

		/**
		 * Texte du SMS d'un statut de commande.
		 *
		 * @param string    $body   Texte (modèle rendu).
		 * @param \WC_Order $order  Commande.
		 * @param string    $status Statut.
		 */
		$body = (string) apply_filters(
			'labyrinthe_connect_order_sms_body',
			Sms_Text::render( $setting['template'], Order_Variables::for_order( $order, $status ) ),
			$order,
			$status
		);

		$result = $this->sender->send(
			$phone,
			$body,
			array(
				'context'         => 'order_status',
				'order_id'        => $order->get_id(),
				'idempotency_key' => self::idempotency_key( $order->get_id(), $status ),
				'reference'       => self::reference( $order->get_id() ),
				'country'         => '' !== $order->get_billing_country() ? $order->get_billing_country() : $order->get_shipping_country(),
			)
		);

		$this->record( $order, $key, wc_get_order_status_name( $status ), $result, $attempt, self::HOOK_CUSTOMER, array( $order->get_id(), $status ) );
	}

	/**
	 * Action planifiée : SMS « nouvelle commande » aux numéros de l'administrateur.
	 *
	 * @param int|string $order_id Commande.
	 * @param int|string $attempt  Numéro de tentative.
	 */
	public function send_admin_sms( $order_id, $attempt = 1 ): void {
		$order   = wc_get_order( (int) $order_id );
		$attempt = max( 1, (int) $attempt );
		if ( ! $order instanceof \WC_Order || ! $this->settings->get( 'wc_admin_enabled' ) ) {
			return;
		}

		$body = Sms_Text::render( $this->settings->admin_template(), Order_Variables::for_order( $order, $order->get_status() ) );
		foreach ( $this->settings->admin_phones() as $phone ) {
			$key = 'admin:' . $phone;
			if ( isset( self::sent( $order )[ $key ] ) ) {
				continue;
			}
			$result = $this->sender->send(
				$phone,
				$body,
				array(
					'context'         => 'admin_new_order',
					'order_id'        => $order->get_id(),
					'idempotency_key' => self::idempotency_key( $order->get_id(), 'admin-' . $phone ),
					'reference'       => self::reference( $order->get_id() ),
				)
			);
			$this->record( $order, $key, __( 'new order (shop manager)', 'labyrinthe-connect' ), $result, $attempt, self::HOOK_ADMIN, array( $order->get_id() ) );
		}
	}

	/**
	 * Référence passée à l'API avec les SMS d'une commande (« order-<identifiant> ») : renvoyée
	 * par le webhook message.status_changed, elle relie l'accusé de remise à la commande.
	 *
	 * @param int $order_id Commande.
	 */
	public static function reference( int $order_id ): string {
		return self::REFERENCE_PREFIX . $order_id;
	}

	/**
	 * Commande d'une référence « order-<identifiant> », ou 0.
	 *
	 * @param string|null $reference Référence renvoyée par l'API.
	 */
	public static function order_id_from_reference( ?string $reference ): int {
		if ( null === $reference || 1 !== preg_match( '/^' . preg_quote( self::REFERENCE_PREFIX, '/' ) . '([1-9]\d{0,19})$/', $reference, $matches ) ) {
			return 0;
		}

		return (int) $matches[1];
	}

	/**
	 * Clé d'idempotence : site + commande + statut. Rejouée à chaque nouvel essai, elle
	 * garantit qu'un même SMS n'est jamais envoyé deux fois (24 h côté API).
	 *
	 * @param int    $order_id Commande.
	 * @param string $status   Statut (ou « admin-<numéro> »).
	 */
	public static function idempotency_key( int $order_id, string $status ): string {
		return 'wc-' . substr( md5( home_url() ), 0, 8 ) . '-' . $order_id . '-' . $status;
	}

	/**
	 * Note de commande et nouvel essai éventuel.
	 *
	 * @param \WC_Order              $order   Commande.
	 * @param string                 $key     Clé de dédoublonnage (status:processing, admin:243…).
	 * @param string                 $label   Libellé du SMS (statut).
	 * @param Send_Result            $result  Résultat de l'envoi.
	 * @param int                    $attempt Tentative.
	 * @param string                 $hook    Action à replanifier.
	 * @param array<int, int|string> $args Arguments de l'action (sans la tentative).
	 */
	private function record( \WC_Order $order, string $key, string $label, Send_Result $result, int $attempt, string $hook, array $args ): void {
		if ( $result->success ) {
			$sent         = self::sent( $order );
			$sent[ $key ] = $result->message_id;
			$order->update_meta_data( self::SENT_META, $sent );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: SMS label (order status), 2: masked phone number, 3: number of segments, 4: encoding (GSM-7 or Unicode), 5: message id. */
					_n(
						'Labyrinthe Connect: SMS "%1$s" sent to %2$s (%3$d segment, %4$s). Message ID: %5$s',
						'Labyrinthe Connect: SMS "%1$s" sent to %2$s (%3$d segments, %4$s). Message ID: %5$s',
						max( 1, $result->segments ),
						'labyrinthe-connect'
					),
					$label,
					$result->recipient,
					max( 1, $result->segments ),
					'ucs2' === $result->encoding ? __( 'Unicode', 'labyrinthe-connect' ) : 'GSM-7',
					$result->message_id
				) . ( $result->test_mode ? ' ' . __( '(test mode: nothing was actually sent)', 'labyrinthe-connect' ) : '' )
			);

			return;
		}

		if ( $result->retryable && $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			$delay = 5 * MINUTE_IN_SECONDS * $attempt;
			as_schedule_single_action( time() + $delay, $hook, array_merge( $args, array( $attempt + 1 ) ), self::GROUP );
			$order->add_order_note(
				sprintf(
					/* translators: 1: SMS label, 2: masked phone number, 3: error code, 4: delay in minutes. */
					__( 'Labyrinthe Connect: SMS "%1$s" to %2$s not sent yet (%3$s). New attempt in %4$d minutes.', 'labyrinthe-connect' ),
					$label,
					$result->recipient,
					$result->error_code,
					(int) ( $delay / MINUTE_IN_SECONDS )
				) . self::request_id_note( $result )
			);

			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: SMS label, 2: masked phone number, 3: error code, 4: error message. */
				__( 'Labyrinthe Connect: SMS "%1$s" to %2$s failed (%3$s): %4$s', 'labyrinthe-connect' ),
				$label,
				$result->recipient,
				$result->error_code,
				$result->error_message
			) . self::request_id_note( $result )
		);
	}

	/**
	 * « Identifiant de requête : … » ajouté aux notes d'échec (à communiquer au support).
	 *
	 * @param Send_Result $result Résultat de l'envoi.
	 */
	private static function request_id_note( Send_Result $result ): string {
		/* translators: %s: request ID (X-Request-Id), to give to the support. */
		return '' !== $result->request_id ? ' ' . sprintf( __( 'Request ID: %s', 'labyrinthe-connect' ), $result->request_id ) : '';
	}

	/**
	 * Planifie une action unique (Action Scheduler), ou un événement WP-Cron à défaut.
	 *
	 * @param string                 $hook Action.
	 * @param array<int, int|string> $args Arguments.
	 */
	private function enqueue( string $hook, array $args ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Une même action (commande + statut) n'est jamais planifiée deux fois : vérification
			// explicite (le stockage hybride d'Action Scheduler ignore $unique) et $unique = true.
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return;
			}
			as_enqueue_async_action( $hook, $args, self::GROUP, true );

			return;
		}
		$args = array_values( $args );
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time(), $hook, $args );
		}
	}

	/**
	 * SMS déjà envoyés pour une commande.
	 *
	 * @param \WC_Order $order Commande.
	 * @return array<string, string>
	 */
	private static function sent( \WC_Order $order ): array {
		$sent = $order->get_meta( self::SENT_META );

		return is_array( $sent ) ? $sent : array();
	}

	/**
	 * Statut sans le préfixe « wc- ».
	 *
	 * @param string $status Statut.
	 */
	private static function status_slug( string $status ): string {
		return sanitize_key( 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status );
	}
}
