<?php
/**
 * Résultat d'un envoi de SMS.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Résultat d'un envoi (succès ou échec), sans le texte ni le numéro complet.
 */
final class Send_Result {

	/**
	 * Constructeur.
	 *
	 * @param bool   $success       Vrai si l'API a accepté le message.
	 * @param string $recipient     Numéro masqué (24381*****78).
	 * @param string $message_id    Identifiant du message (API), '' en cas d'échec.
	 * @param string $status        Statut (queued… ou « error »).
	 * @param int    $segments      Segments facturés.
	 * @param string $encoding      gsm7 ou ucs2.
	 * @param string $error_code    Code d'erreur stable (INSUFFICIENT_CREDITS, CONNECTION_ERROR…).
	 * @param string $error_message Message d'erreur (pour les humains).
	 * @param bool   $retryable     Vrai si un nouvel essai a des chances de réussir (réseau, 5xx, 429).
	 * @param bool   $test_mode     Vrai avec une clé lsk_test_ (rien n'est réellement envoyé).
	 * @param bool   $replayed      Vrai si l'API a rejoué un envoi précédent (même Idempotency-Key).
	 * @param int    $log_id        Ligne du journal.
	 * @param string $request_id    Identifiant de la requête (X-Request-Id), à communiquer au support.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $recipient,
		public readonly string $message_id = '',
		public readonly string $status = '',
		public readonly int $segments = 0,
		public readonly string $encoding = '',
		public readonly string $error_code = '',
		public readonly string $error_message = '',
		public readonly bool $retryable = false,
		public readonly bool $test_mode = false,
		public readonly bool $replayed = false,
		public readonly int $log_id = 0,
		public readonly string $request_id = '',
	) {
	}

	/**
	 * Copie avec l'identifiant de ligne du journal.
	 *
	 * @param int $log_id Ligne du journal.
	 */
	public function with_log_id( int $log_id ): self {
		return new self( $this->success, $this->recipient, $this->message_id, $this->status, $this->segments, $this->encoding, $this->error_code, $this->error_message, $this->retryable, $this->test_mode, $this->replayed, $log_id, $this->request_id );
	}

	/**
	 * Données publiques (actions WordPress, réponses AJAX).
	 *
	 * @return array<string, scalar>
	 */
	public function to_array(): array {
		return array(
			'success'       => $this->success,
			'recipient'     => $this->recipient,
			'message_id'    => $this->message_id,
			'status'        => $this->status,
			'segments'      => $this->segments,
			'encoding'      => $this->encoding,
			'error_code'    => $this->error_code,
			'error_message' => $this->error_message,
			'retryable'     => $this->retryable,
			'test_mode'     => $this->test_mode,
			'replayed'      => $this->replayed,
			'log_id'        => $this->log_id,
			'request_id'    => $this->request_id,
		);
	}
}
