<?php
/**
 * Envoi d'un SMS par le plugin (commandes, SMS de test).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ApiException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ConnectionException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\FeatureDisabledException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\IdempotencyKeyUnresolvedException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\LabyrintheException;

defined( 'ABSPATH' ) || exit;

/**
 * Point de passage unique des SMS du plugin : envoi par le SDK avec une Idempotency-Key,
 * une référence (commande), le retrait des accents par l'API si le réglage est actif et un
 * X-Request-Id, puis inscription au journal. Ne lève jamais d'exception : le résultat dit
 * ce qui s'est passé (avec l'identifiant de requête à communiquer au support en cas d'échec).
 */
final class Sms_Sender {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Fabrique du client.
	 *
	 * @var Client_Factory
	 */
	private Client_Factory $clients;

	/**
	 * Journal.
	 *
	 * @var Message_Log
	 */
	private Message_Log $log;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param Client_Factory $clients  Fabrique du client.
	 * @param Message_Log    $log      Journal.
	 */
	public function __construct( Settings $settings, Client_Factory $clients, Message_Log $log ) {
		$this->settings = $settings;
		$this->clients  = $clients;
		$this->log      = $log;
	}

	/**
	 * Texte envoyé : modèle rendu, espaces de début et de fin retirés. Le retrait des accents
	 * (réglage « Accents ») est fait par l'API (strip_accents), comme le calcul des segments.
	 *
	 * @param string $body Texte.
	 */
	public function prepare_body( string $body ): string {
		return trim( $body );
	}

	/**
	 * Envoie un SMS.
	 *
	 * @param string                                                                                                 $phone Numéro (tous formats).
	 * @param string                                                                                                 $body  Texte.
	 * @param array{context: string, order_id?: int, idempotency_key?: string, reference?: string, country?: string} $meta  Contexte de l'envoi.
	 */
	public function send( string $phone, string $body, array $meta ): Send_Result {
		$context   = $meta['context'];
		$order_id  = (int) ( $meta['order_id'] ?? 0 );
		$test_mode = $this->settings->is_test_mode();
		$number    = Phone::normalize( $phone, $this->settings->country_code(), $meta['country'] ?? null );
		$masked    = Phone::mask( null !== $number ? $number : $phone );

		/**
		 * Texte d'un SMS avant envoi.
		 *
		 * @param string $body Texte.
		 * @param array  $meta Contexte (context, order_id…).
		 */
		$body = (string) apply_filters( 'labyrinthe_connect_sms_body', $this->prepare_body( $body ), $meta );
		$text = Sms_Text::analyse( $body );

		if ( null === $number ) {
			$result = new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $text['encoding'], 'INVALID_PHONE', __( 'The phone number is not valid.', 'labyrinthe-connect' ), false, $test_mode );
		} elseif ( '' === $body ) {
			$result = new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, '', 'EMPTY_MESSAGE', __( 'The message is empty.', 'labyrinthe-connect' ), false, $test_mode );
		} else {
			$result = $this->dispatch( $number, $masked, $body, $meta, $test_mode, $text['encoding'] );
		}

		$log_id = $this->log->insert(
			array(
				'message_id'    => $result->message_id,
				'recipient'     => $result->recipient,
				'context'       => $context,
				'order_id'      => $order_id,
				'status'        => $result->status,
				'segments'      => $result->segments,
				'encoding'      => $result->encoding,
				'error_code'    => $result->error_code,
				'error_message' => $result->error_message,
				'test_mode'     => $test_mode,
				'request_id'    => $result->request_id,
			)
		);
		$result = $result->with_log_id( $log_id );

		/**
		 * Après chaque tentative d'envoi d'un SMS par le plugin (succès ou échec).
		 *
		 * @param array $result Résultat (success, message_id, status, error_code…), sans texte ni numéro complet.
		 * @param array $meta   Contexte (context, order_id…).
		 */
		do_action( 'labyrinthe_connect_sms_sent', $result->to_array(), $meta );

		return $result;
	}

	/**
	 * Appel à l'API.
	 *
	 * @param string               $number    Numéro normalisé.
	 * @param string               $masked    Numéro masqué.
	 * @param string               $body      Texte.
	 * @param array<string, mixed> $meta      Contexte.
	 * @param bool                 $test_mode Mode test.
	 * @param string               $encoding  Encodage estimé.
	 */
	private function dispatch( string $number, string $masked, string $body, array $meta, bool $test_mode, string $encoding ): Send_Result {
		$reference       = isset( $meta['reference'] ) ? mb_substr( (string) $meta['reference'], 0, 64 ) : null;
		$idempotency_key = isset( $meta['idempotency_key'] ) ? substr( (string) $meta['idempotency_key'], 0, 255 ) : null;
		$request_id      = Account_Status::new_request_id();

		try {
			$client = $this->clients->make();
			// « + » : le numéro est déjà international ; sans lui, le SDK compléterait un numéro
			// étranger court (10 chiffres ou moins) avec l'indicatif par défaut.
			$message = $client->sms->send(
				to: '+' . $number,
				body: $body,
				reference: $reference,
				idempotencyKey: $idempotency_key,
				stripAccents: $this->settings->strip_accents(),
				requestId: $request_id
			);
			Account_Status::record_success();

			return new Send_Result(
				true,
				$masked,
				$message->id,
				$message->status->value,
				$message->segments,
				null !== $message->encoding ? $message->encoding->value : $encoding,
				'',
				'',
				false,
				$test_mode,
				false,
				0,
				$request_id
			);
		} catch ( FeatureDisabledException $e ) {
			// Maintenance de la plateforme (503 FEATURE_DISABLED) : rien n'est parti. Nouvel essai
			// possible, après Retry-After quand une réactivation automatique est programmée.
			$message = Account_Status::maintenance_message( $e, __( 'Sending SMS through Labyrinthe Connect is temporarily unavailable (platform maintenance). Nothing was sent.', 'labyrinthe-connect' ) );

			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, $e->getErrorCode(), $message, true, $test_mode, false, 0, Account_Status::request_id( $e, $request_id ), (int) $e->getRetryAfter() );
		} catch ( ApiException $e ) {
			Account_Status::record_error( $e );
			// IDEMPOTENCY_KEY_UNRESOLVED : résultat inconnu (un SMS a pu partir), jamais relancé.
			$unresolved = $e instanceof IdempotencyKeyUnresolvedException;
			$retryable  = ! $unresolved && ( $e->getStatusCode() >= 500 || in_array( $e->getErrorCode(), array( 'TOO_MANY_REQUESTS', 'IDEMPOTENCY_REQUEST_IN_PROGRESS' ), true ) );
			if ( Account_Status::is_suspension( $e ) ) {
				$message = __( 'Your Labyrinthe Connect account is suspended: no SMS can be sent until it is reactivated. Contact support.', 'labyrinthe-connect' );
			} elseif ( $unresolved ) {
				$message = __( 'The outcome of this SMS is unknown: it may have been sent. It will not be sent again automatically; check the messages in your Labyrinthe Connect dashboard before sending it manually.', 'labyrinthe-connect' );
			} else {
				$message = $e->getMessage();
			}

			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, $e->getErrorCode(), $message, $retryable, $test_mode, false, 0, Account_Status::request_id( $e, $request_id ) );
		} catch ( ConnectionException $e ) {
			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, $e->getErrorCode(), __( 'The Labyrinthe Connect API could not be reached.', 'labyrinthe-connect' ), true, $test_mode, false, 0, $request_id );
		} catch ( Configuration_Exception $e ) {
			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, 'NOT_CONFIGURED', $e->getMessage(), false, $test_mode );
		} catch ( LabyrintheException $e ) {
			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, 'INVALID_REQUEST', $e->getMessage(), false, $test_mode );
		}
	}
}
