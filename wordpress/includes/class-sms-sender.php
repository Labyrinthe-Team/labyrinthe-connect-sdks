<?php
/**
 * Envoi d'un SMS par le plugin (commandes, SMS de test).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ApiException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ConnectionException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\LabyrintheException;

defined( 'ABSPATH' ) || exit;

/**
 * Point de passage unique des SMS du plugin : nettoyage du texte (accents), envoi par le
 * SDK avec une Idempotency-Key, inscription au journal. Ne lève jamais d'exception :
 * le résultat dit ce qui s'est passé.
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
	 * Texte réellement envoyé : modèle rendu, accents retirés si l'option est active.
	 *
	 * @param string $body Texte.
	 */
	public function prepare_body( string $body ): string {
		if ( $this->settings->strip_accents() ) {
			$body = Sms_Text::strip_special_characters( $body );
		}

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

		try {
			$client = $this->clients->make();
			// « + » : le numéro est déjà international, le SDK ne doit pas y ajouter l'indicatif par défaut.
			$message = $client->sms->send( to: '+' . $number, body: $body, reference: $reference, idempotencyKey: $idempotency_key );

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
				$test_mode
			);
		} catch ( ApiException $e ) {
			$retryable = $e->getStatusCode() >= 500 || 'TOO_MANY_REQUESTS' === $e->getErrorCode();

			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, $e->getErrorCode(), $e->getMessage(), $retryable, $test_mode );
		} catch ( ConnectionException $e ) {
			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, $e->getErrorCode(), __( 'The Labyrinthe Connect API could not be reached.', 'labyrinthe-connect' ), true, $test_mode );
		} catch ( Configuration_Exception $e ) {
			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, 'NOT_CONFIGURED', $e->getMessage(), false, $test_mode );
		} catch ( LabyrintheException $e ) {
			return new Send_Result( false, $masked, '', Message_Log::STATUS_ERROR, 0, $encoding, 'INVALID_REQUEST', $e->getMessage(), false, $test_mode );
		}
	}
}
