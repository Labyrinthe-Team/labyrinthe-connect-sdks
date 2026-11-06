<?php
/**
 * Envoi et vérification des codes OTP pour le site.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

use LabyrintheConnect\Account_Status;
use LabyrintheConnect\Client_Factory;
use LabyrintheConnect\Configuration_Exception;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Phone;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ApiException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\IdempotencyKeyUnresolvedException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\IdempotencyRequestInProgressException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\LabyrintheException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\NotFoundException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\OtpRateLimitedException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\RateLimitException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Model\OtpSendResult;

defined( 'ABSPATH' ) || exit;

/**
 * Parcours OTP : send() puis verify(), l'otp_id restant côté serveur (Session).
 *
 * Résultats : tableaux { ok, code, message, … } prêts pour une réponse JSON. Le code OTP
 * n'est jamais journalisé ; test_code (clé lsk_test_) n'est renvoyé qu'aux administrateurs.
 *
 * Chaque envoi porte une Idempotency-Key stable (site + session + contexte + numéro + rang de
 * l'envoi) : un double clic ou une relance après une coupure réseau rejoue le même envoi (même
 * otp_id, aucun second SMS) ; seul un nouveau clic sur « Renvoyer » après un envoi réussi en
 * crée un autre.
 */
final class Otp_Service {

	public const CONTEXT_LOGIN    = 'login';
	public const CONTEXT_REGISTER = 'register';
	public const CONTEXT_CHECKOUT = 'checkout';

	/** Validité d'un numéro vérifié pour terminer l'inscription ou la commande. */
	public const VERIFIED_TTL = 30 * MINUTE_IN_SECONDS;

	/** Délai anti-renvoi par défaut, tant que l'API n'en a indiqué aucun (resend_after_seconds). */
	public const RESEND_HINT = 60;

	/**
	 * Option : forme des codes du compte (longueur, type, tentatives, délai anti-renvoi), relevée
	 * au dernier envoi réel ; le code fictif d'un numéro inconnu a la même forme.
	 */
	public const SHAPE_OPTION = 'labyrinthe_connect_otp_shape';

	/** Fenêtre de limitation par IP, en secondes. */
	public const RATE_WINDOW = HOUR_IN_SECONDS;

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
	 * Session OTP.
	 *
	 * @var Session
	 */
	private Session $session;

	/**
	 * Limitation par IP.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $limiter;

	/**
	 * Comptes par numéro.
	 *
	 * @var Phone_Users
	 */
	private Phone_Users $users;

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
	 * @param Session        $session  Session OTP.
	 * @param Rate_Limiter   $limiter  Limitation par IP.
	 * @param Phone_Users    $users    Comptes par numéro.
	 * @param Message_Log    $log      Journal.
	 */
	public function __construct( Settings $settings, Client_Factory $clients, Session $session, Rate_Limiter $limiter, Phone_Users $users, Message_Log $log ) {
		$this->settings = $settings;
		$this->clients  = $clients;
		$this->session  = $session;
		$this->limiter  = $limiter;
		$this->users    = $users;
		$this->log      = $log;
	}

	/**
	 * Contextes activés dans les réglages.
	 *
	 * @return list<string>
	 */
	public function enabled_contexts(): array {
		$contexts = array();
		if ( $this->settings->get( 'otp_login_enabled' ) ) {
			$contexts[] = self::CONTEXT_LOGIN;
		}
		if ( $this->settings->get( 'otp_register_enabled' ) ) {
			$contexts[] = self::CONTEXT_REGISTER;
		}
		if ( $this->settings->get( 'otp_checkout_enabled' ) && class_exists( 'WooCommerce' ) ) {
			$contexts[] = self::CONTEXT_CHECKOUT;
		}

		return $this->settings->has_api_key() ? $contexts : array();
	}

	/**
	 * Envoie un code au numéro saisi.
	 *
	 * @param string $context Contexte (login, register, checkout).
	 * @param string $raw     Numéro saisi.
	 * @return array<string, mixed>
	 */
	public function send( string $context, string $raw ): array {
		$limit = (int) $this->settings->get( 'otp_ip_send_limit' );
		if ( ! $this->limiter->hit( 'send', max( 1, $limit ), self::RATE_WINDOW ) ) {
			return self::failure( 'IP_RATE_LIMITED', __( 'Too many codes were requested from this connection. Please try again later.', 'labyrinthe-connect' ), $this->limiter->retry_after( 'send', self::RATE_WINDOW ) );
		}

		$phone = Phone::normalize( $raw, $this->settings->country_code() );
		if ( null === $phone ) {
			return self::failure( 'INVALID_PHONE', __( 'Please enter a valid phone number, for example 0812345678 or +243812345678.', 'labyrinthe-connect' ) );
		}
		$masked = Phone::mask( $phone );
		$data   = $this->session->get();
		$shape  = self::shape();

		// Rang de cet envoi pour ce numéro dans cette session : inchangé tant qu'aucun envoi n'a
		// abouti, donc la même clé d'idempotence pour un double clic ou une relance.
		$slot = $context . '|' . $phone;
		$rank = ( $data['sends'][ $slot ] ?? 0 ) + 1;

		$user_id = 0;
		if ( self::CONTEXT_LOGIN === $context ) {
			$user = $this->users->find_login_user( $phone );
			if ( null === $user ) {
				// Aucun SMS (ni crédit dépensé) pour un numéro sans compte, mais la même réponse
				// qu'un envoi réel : on ne révèle pas quels numéros ont un compte.
				$data['pending'][ $context ] = array(
					'otp_id'       => '',
					'phone'        => $phone,
					'user_id'      => 0,
					'attempts'     => 0,
					'max_attempts' => $shape['max_attempts'],
					'expires'      => time() + 10 * MINUTE_IN_SECONDS,
				);
				$data['sends'][ $slot ]      = $rank;
				$this->session->save( $data );

				return self::sent( $masked, 10 * MINUTE_IN_SECONDS, $shape, null );
			}
			$user_id = $user->ID;
		}

		$request_id = Account_Status::new_request_id();
		try {
			// Relance automatique sans risque (réseau, 5xx, 429) : la clé d'idempotence évite tout second SMS.
			$result = $this->clients->make( 10.0, 1 )->otp->send(
				phone: '+' . $phone,
				template: $this->settings->otp_template(),
				locale: self::locale(),
				webOtpDomain: $this->settings->web_otp_domain(),
				stripAccents: $this->settings->strip_accents(),
				idempotencyKey: $this->idempotency_key( $slot, $rank ),
				requestId: $request_id
			);
		} catch ( OtpResendTooSoonException $e ) {
			$wait = $e->getRetryAfter() ?? $shape['resend_after'];
			/* translators: %d: number of seconds. */
			return self::failure( 'OTP_RESEND_TOO_SOON', sprintf( _n( 'Please wait %d second before requesting a new code.', 'Please wait %d seconds before requesting a new code.', $wait, 'labyrinthe-connect' ), $wait ), $wait );
		} catch ( OtpRateLimitedException $e ) {
			$this->log_send( $context, $masked, $e->getErrorCode(), $e->getMessage(), Account_Status::request_id( $e, $request_id ) );

			return self::failure( 'OTP_RATE_LIMITED', __( 'Too many codes were requested for this number. Please try again later.', 'labyrinthe-connect' ), $e->getRetryAfter() );
		} catch ( RateLimitException | IdempotencyRequestInProgressException $e ) {
			return self::failure( 'TOO_MANY_REQUESTS', __( 'The service is busy. Please try again in a moment.', 'labyrinthe-connect' ), $e->getRetryAfter() ?? 10 );
		} catch ( IdempotencyKeyUnresolvedException $e ) {
			// Résultat inconnu : un code a pu partir. Jamais relancé ; la prochaine demande du
			// visiteur (qui sait s'il a reçu le code) part avec une nouvelle clé d'idempotence.
			$this->log_send( $context, $masked, $e->getErrorCode(), $e->getMessage(), Account_Status::request_id( $e, $request_id ) );
			$data['sends'][ $slot ] = $rank;
			$this->session->save( $data );

			return self::failure( 'SERVICE_UNAVAILABLE', __( 'We could not confirm that the code was sent. If you do not receive it, request a new code.', 'labyrinthe-connect' ) );
		} catch ( ApiException $e ) {
			Account_Status::record_error( $e );
			$this->log_send( $context, $masked, $e->getErrorCode(), $e->getMessage(), Account_Status::request_id( $e, $request_id ) );
			if ( in_array( $e->getErrorCode(), array( 'INVALID_PHONE', 'RECIPIENT_NOT_SENDABLE' ), true ) ) {
				return self::failure( $e->getErrorCode(), __( 'This number cannot receive SMS. Please check it or use another number.', 'labyrinthe-connect' ) );
			}

			return self::failure( 'SERVICE_UNAVAILABLE', __( 'The code could not be sent. Please try again later.', 'labyrinthe-connect' ) );
		} catch ( LabyrintheException | Configuration_Exception $e ) {
			$this->log_send( $context, $masked, $e instanceof Configuration_Exception ? 'NOT_CONFIGURED' : 'CONNECTION_ERROR', $e->getMessage(), $e instanceof Configuration_Exception ? '' : $request_id );

			return self::failure( 'SERVICE_UNAVAILABLE', __( 'The code could not be sent. Please try again later.', 'labyrinthe-connect' ) );
		}
		Account_Status::record_success();

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du SDK.
		$shape                       = self::remember_shape( $result );
		$data['pending'][ $context ] = array(
			'otp_id'       => $result->otpId,
			'phone'        => $phone,
			'user_id'      => $user_id,
			'attempts'     => 0,
			'max_attempts' => $result->maxAttempts,
			'expires'      => $result->expiresAt->getTimestamp(),
		);
		$data['sends'][ $slot ]      = $rank;
		$this->session->save( $data );
		// Un rejeu (Idempotent-Replayed) n'a envoyé aucun SMS : rien de plus au journal.
		if ( ! $result->replayed ) {
			$this->log_send( $context, $masked, '', '', $request_id );
		}

		// Le code de test (clé lsk_test_ uniquement) n'est montré qu'à un administrateur.
		$test_code  = $this->settings->is_test_mode() && current_user_can( 'manage_options' ) ? $result->testCode() : null;
		$expires_in = max( 60, $result->expiresAt->getTimestamp() - time() );
		// phpcs:enable

		return self::sent( $masked, $expires_in, $shape, $test_code );
	}

	/**
	 * Vérifie le code saisi pour le code en attente de ce contexte.
	 *
	 * @param string $context Contexte.
	 * @param string $code    Code saisi.
	 * @return array<string, mixed> Avec « phone » et « user_id » en cas de succès.
	 */
	public function verify( string $context, string $code ): array {
		if ( ! $this->limiter->hit( 'verify', 20, self::RATE_WINDOW ) ) {
			return self::failure( 'IP_RATE_LIMITED', __( 'Too many attempts from this connection. Please try again later.', 'labyrinthe-connect' ), $this->limiter->retry_after( 'verify', self::RATE_WINDOW ) );
		}

		$data    = $this->session->get();
		$pending = $data['pending'][ $context ] ?? null;
		if ( ! is_array( $pending ) || (int) $pending['expires'] < time() ) {
			unset( $data['pending'][ $context ] );
			$this->session->save( $data );

			return self::failure( 'NO_PENDING_CODE', __( 'This code has expired. Please request a new one.', 'labyrinthe-connect' ) );
		}

		$code = (string) preg_replace( '/\s+/', '', $code );
		if ( '' === $code || strlen( $code ) > 20 ) {
			return self::failure( 'invalid_code', __( 'Please enter the code received by SMS.', 'labyrinthe-connect' ) );
		}

		// Numéro sans compte (connexion) : aucun code n'existe, chaque essai échoue, avec le même
		// décompte des tentatives qu'un vrai code.
		if ( '' === (string) $pending['otp_id'] ) {
			$pending['attempts'] = (int) $pending['attempts'] + 1;
			$remaining           = max( 0, (int) ( $pending['max_attempts'] ?? self::shape()['max_attempts'] ) - $pending['attempts'] );
			if ( 0 === $remaining ) {
				unset( $data['pending'][ $context ] );
				$this->session->save( $data );

				return self::reason_failure( 'max_attempts_reached', 0 );
			}
			$data['pending'][ $context ] = $pending;
			$this->session->save( $data );

			return self::reason_failure( 'invalid_code', $remaining );
		}

		try {
			$result = $this->clients->make( 10.0, 0 )->otp->verify( (string) $pending['otp_id'], $code, Account_Status::new_request_id() );
		} catch ( NotFoundException $e ) {
			unset( $data['pending'][ $context ] );
			$this->session->save( $data );

			return self::reason_failure( 'expired' );
		} catch ( LabyrintheException | Configuration_Exception $e ) {
			Account_Status::record_error( $e );

			return self::failure( 'SERVICE_UNAVAILABLE', __( 'The code could not be checked. Please try again.', 'labyrinthe-connect' ) );
		}

		if ( ! $result->isValid() ) {
			$reason = null !== $result->reason ? $result->reason->value : 'invalid_code';
			if ( 'invalid_code' !== $reason ) {
				unset( $data['pending'][ $context ] );
				$this->session->save( $data );
			}

			return self::reason_failure( $reason, $result->attemptsRemaining ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
		}

		unset( $data['pending'][ $context ] );
		$phone = (string) $pending['phone'];
		if ( self::CONTEXT_LOGIN !== $context ) {
			$data['verified'][ $context . '|' . $phone ] = time() + self::VERIFIED_TTL;
		}
		$this->session->save( $data );

		return array(
			'ok'      => true,
			'code'    => 'verified',
			'message' => __( 'Phone number verified.', 'labyrinthe-connect' ),
			'phone'   => $phone,
			'user_id' => (int) $pending['user_id'],
		);
	}

	/**
	 * Clé d'idempotence d'un envoi : propre au site, à la session (empreinte du cookie), au
	 * contexte, au numéro et au rang de l'envoi. Ne révèle ni le numéro ni le jeton.
	 *
	 * @param string $slot Contexte et numéro (« register|243812345678 »).
	 * @param int    $rank Rang de l'envoi dans la session.
	 */
	private function idempotency_key( string $slot, int $rank ): string {
		return 'wp-otp-' . substr( md5( home_url() ), 0, 8 ) . '-' . substr( hash( 'sha256', $this->session->fingerprint() . '|' . $slot ), 0, 32 ) . '-' . $rank;
	}

	/**
	 * Forme des codes du compte (dernier envoi réel), ou valeurs par défaut.
	 *
	 * @return array{code_length: int, code_type: string, max_attempts: int, resend_after: int}
	 */
	public static function shape(): array {
		$stored = get_option( self::SHAPE_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$type   = (string) ( $stored['code_type'] ?? 'numeric' );

		return array(
			'code_length'  => max( 4, min( 8, (int) ( $stored['code_length'] ?? 6 ) ) ),
			'code_type'    => in_array( $type, array( 'numeric', 'alphanumeric' ), true ) ? $type : 'numeric',
			'max_attempts' => max( 1, (int) ( $stored['max_attempts'] ?? 5 ) ),
			'resend_after' => max( 0, (int) ( $stored['resend_after'] ?? self::RESEND_HINT ) ),
		);
	}

	/**
	 * Mémorise la forme des codes renvoyée par l'API et la renvoie.
	 *
	 * @param OtpSendResult $result Résultat de l'envoi.
	 * @return array{code_length: int, code_type: string, max_attempts: int, resend_after: int}
	 */
	private static function remember_shape( OtpSendResult $result ): array {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés du SDK.
		$shape = self::shape();
		if ( $result->codeLength > 0 ) {
			$shape['code_length'] = max( 4, min( 8, $result->codeLength ) );
		}
		if ( 'unknown' !== $result->codeType->value ) {
			$shape['code_type'] = $result->codeType->value;
		}
		$shape['max_attempts'] = max( 1, $result->maxAttempts );
		$shape['resend_after'] = max( 0, $result->resendAfterSeconds );
		// phpcs:enable

		if ( get_option( self::SHAPE_OPTION ) !== $shape ) {
			update_option( self::SHAPE_OPTION, $shape, false );
		}

		return $shape;
	}

	/**
	 * Vrai si ce numéro a été vérifié dans cette session pour ce contexte.
	 *
	 * @param string $context Contexte.
	 * @param string $phone   Numéro normalisé.
	 */
	public function is_verified( string $context, string $phone ): bool {
		$data = $this->session->get();

		return isset( $data['verified'][ $context . '|' . $phone ] );
	}

	/**
	 * Consomme une vérification (inscription terminée).
	 *
	 * @param string $context Contexte.
	 * @param string $phone   Numéro normalisé.
	 */
	public function forget_verification( string $context, string $phone ): void {
		$data = $this->session->get();
		unset( $data['verified'][ $context . '|' . $phone ] );
		$this->session->save( $data );
	}

	/**
	 * Inscrit l'envoi d'un code au journal (sans le code).
	 *
	 * @param string $context    Contexte.
	 * @param string $masked     Numéro masqué.
	 * @param string $error_code Code d'erreur ('' : succès).
	 * @param string $error      Message d'erreur.
	 * @param string $request_id Identifiant de la requête (X-Request-Id).
	 */
	private function log_send( string $context, string $masked, string $error_code, string $error, string $request_id ): void {
		$this->log->insert(
			array(
				'recipient'     => $masked,
				'context'       => 'otp_' . $context,
				'status'        => '' === $error_code ? Message_Log::STATUS_OTP_SENT : Message_Log::STATUS_ERROR,
				'error_code'    => $error_code,
				'error_message' => $error,
				'test_mode'     => $this->settings->is_test_mode(),
				'request_id'    => $request_id,
			)
		);
	}

	/**
	 * Langue du modèle standard : celle du site (fr ou en).
	 */
	private static function locale(): string {
		return 0 === strpos( determine_locale(), 'en' ) ? 'en' : 'fr';
	}

	/**
	 * Réponse « code envoyé ».
	 *
	 * @param string                                                                           $masked     Numéro masqué.
	 * @param int                                                                              $expires_in Validité, en secondes.
	 * @param array{code_length: int, code_type: string, max_attempts: int, resend_after: int} $shape      Forme du code.
	 * @param string|null                                                                      $test_code  Code de test (administrateurs, mode test).
	 * @return array<string, mixed>
	 */
	private static function sent( string $masked, int $expires_in, array $shape, ?string $test_code ): array {
		$response = array(
			'ok'          => true,
			'code'        => 'sent',
			/* translators: %s: masked phone number. */
			'message'     => sprintf( __( 'A code was sent by SMS to %s.', 'labyrinthe-connect' ), $masked ),
			'expires_in'  => $expires_in,
			'resend_in'   => $shape['resend_after'],
			'code_length' => $shape['code_length'],
			'code_type'   => $shape['code_type'],
		);
		if ( null !== $test_code ) {
			$response['test_code'] = $test_code;
		}

		return $response;
	}

	/**
	 * Échec de vérification avec un motif de l'API.
	 *
	 * @param string   $reason    invalid_code, expired, already_used, max_attempts_reached.
	 * @param int|null $remaining Tentatives restantes (attempts_remaining), si connues.
	 * @return array<string, mixed>
	 */
	private static function reason_failure( string $reason, ?int $remaining = null ): array {
		switch ( $reason ) {
			case 'expired':
				return self::failure( $reason, __( 'This code has expired. Please request a new one.', 'labyrinthe-connect' ) );
			case 'already_used':
				return self::failure( $reason, __( 'This code was already used. Please request a new one.', 'labyrinthe-connect' ) );
			case 'max_attempts_reached':
				$response                       = self::failure( $reason, __( 'Too many wrong codes. Please request a new one.', 'labyrinthe-connect' ) );
				$response['attempts_remaining'] = 0;

				return $response;
			default:
				if ( null === $remaining ) {
					return self::failure( 'invalid_code', __( 'Wrong code. Please check the SMS and try again.', 'labyrinthe-connect' ) );
				}
				$response = self::failure(
					'invalid_code',
					sprintf(
						/* translators: %d: number of attempts left. */
						_n( 'Wrong code. Please check the SMS and try again (%d attempt left).', 'Wrong code. Please check the SMS and try again (%d attempts left).', $remaining, 'labyrinthe-connect' ),
						$remaining
					)
				);
				$response['attempts_remaining'] = $remaining;

				return $response;
		}
	}

	/**
	 * Réponse d'échec.
	 *
	 * @param string   $code        Code stable.
	 * @param string   $message     Message pour l'utilisateur.
	 * @param int|null $retry_after Secondes d'attente (compte à rebours).
	 * @return array<string, mixed>
	 */
	private static function failure( string $code, string $message, ?int $retry_after = null ): array {
		$response = array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $retry_after ) {
			$response['retry_after'] = max( 1, $retry_after );
		}

		return $response;
	}
}
