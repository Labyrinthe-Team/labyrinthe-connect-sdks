<?php
/**
 * Envoi et vérification des codes OTP pour le site.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

use LabyrintheConnect\Client_Factory;
use LabyrintheConnect\Configuration_Exception;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Phone;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ApiException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\LabyrintheException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\NotFoundException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\OtpRateLimitedException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\RateLimitException;

defined( 'ABSPATH' ) || exit;

/**
 * Parcours OTP : send() puis verify(), l'otp_id restant côté serveur (Session).
 *
 * Résultats : tableaux { ok, code, message, … } prêts pour une réponse JSON. Le code OTP
 * n'est jamais journalisé ; test_code (clé lsk_test_) n'est renvoyé qu'aux administrateurs.
 */
final class Otp_Service {

	public const CONTEXT_LOGIN    = 'login';
	public const CONTEXT_REGISTER = 'register';
	public const CONTEXT_CHECKOUT = 'checkout';

	/** Validité d'un numéro vérifié pour terminer l'inscription ou la commande. */
	public const VERIFIED_TTL = 30 * MINUTE_IN_SECONDS;

	/** Délai d'attente conseillé avant de redemander un code (l'API reste l'autorité). */
	public const RESEND_HINT = 30;

	/** Essais permis sur un code fictif (connexion d'un numéro inconnu). */
	private const DECOY_ATTEMPTS = 5;

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

		$user_id = 0;
		if ( self::CONTEXT_LOGIN === $context ) {
			$user = $this->users->find_login_user( $phone );
			if ( null === $user ) {
				// Aucun SMS (ni crédit dépensé) pour un numéro sans compte, mais la même réponse
				// qu'un envoi réel : on ne révèle pas quels numéros ont un compte.
				$data['pending'][ $context ] = array(
					'otp_id'   => '',
					'phone'    => $phone,
					'user_id'  => 0,
					'attempts' => 0,
					'expires'  => time() + 10 * MINUTE_IN_SECONDS,
				);
				$this->session->save( $data );

				return self::sent( $masked, 10 * MINUTE_IN_SECONDS, null );
			}
			$user_id = $user->ID;
		}

		try {
			$result = $this->clients->make( 10.0, 0 )->otp->send( '+' . $phone, $this->settings->otp_template(), self::locale() );
		} catch ( OtpResendTooSoonException $e ) {
			$wait = $e->getRetryAfter() ?? self::RESEND_HINT;
			/* translators: %d: number of seconds. */
			return self::failure( 'OTP_RESEND_TOO_SOON', sprintf( _n( 'Please wait %d second before requesting a new code.', 'Please wait %d seconds before requesting a new code.', $wait, 'labyrinthe-connect' ), $wait ), $wait );
		} catch ( OtpRateLimitedException $e ) {
			$this->log_send( $context, $masked, $e->getErrorCode(), $e->getMessage() );

			return self::failure( 'OTP_RATE_LIMITED', __( 'Too many codes were requested for this number. Please try again later.', 'labyrinthe-connect' ) );
		} catch ( RateLimitException $e ) {
			return self::failure( 'TOO_MANY_REQUESTS', __( 'The service is busy. Please try again in a moment.', 'labyrinthe-connect' ), $e->getRetryAfter() ?? 10 );
		} catch ( ApiException $e ) {
			$this->log_send( $context, $masked, $e->getErrorCode(), $e->getMessage() );
			if ( in_array( $e->getErrorCode(), array( 'INVALID_PHONE', 'RECIPIENT_NOT_SENDABLE' ), true ) ) {
				return self::failure( $e->getErrorCode(), __( 'This number cannot receive SMS. Please check it or use another number.', 'labyrinthe-connect' ) );
			}

			return self::failure( 'SERVICE_UNAVAILABLE', __( 'The code could not be sent. Please try again later.', 'labyrinthe-connect' ) );
		} catch ( LabyrintheException | Configuration_Exception $e ) {
			$this->log_send( $context, $masked, $e instanceof Configuration_Exception ? 'NOT_CONFIGURED' : 'CONNECTION_ERROR', $e->getMessage() );

			return self::failure( 'SERVICE_UNAVAILABLE', __( 'The code could not be sent. Please try again later.', 'labyrinthe-connect' ) );
		}

		$data['pending'][ $context ] = array(
			'otp_id'   => $result->otpId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
			'phone'    => $phone,
			'user_id'  => $user_id,
			'attempts' => 0,
			'expires'  => $result->expiresAt->getTimestamp(), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
		);
		$this->session->save( $data );
		$this->log_send( $context, $masked, '', '' );

		// Le code de test (clé lsk_test_ uniquement) n'est montré qu'à un administrateur.
		$test_code = $this->settings->is_test_mode() && current_user_can( 'manage_options' ) ? $result->testCode() : null;

		return self::sent( $masked, max( 60, $result->expiresAt->getTimestamp() - time() ), $test_code ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
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

		// Numéro sans compte (connexion) : aucun code n'existe, chaque essai échoue.
		if ( '' === (string) $pending['otp_id'] ) {
			$pending['attempts'] = (int) $pending['attempts'] + 1;
			if ( $pending['attempts'] >= self::DECOY_ATTEMPTS ) {
				unset( $data['pending'][ $context ] );
				$this->session->save( $data );

				return self::reason_failure( 'max_attempts_reached' );
			}
			$data['pending'][ $context ] = $pending;
			$this->session->save( $data );

			return self::reason_failure( 'invalid_code' );
		}

		try {
			$result = $this->clients->make( 10.0, 0 )->otp->verify( (string) $pending['otp_id'], $code );
		} catch ( NotFoundException $e ) {
			unset( $data['pending'][ $context ] );
			$this->session->save( $data );

			return self::reason_failure( 'expired' );
		} catch ( LabyrintheException | Configuration_Exception $e ) {
			return self::failure( 'SERVICE_UNAVAILABLE', __( 'The code could not be checked. Please try again.', 'labyrinthe-connect' ) );
		}

		if ( ! $result->isValid() ) {
			$reason = null !== $result->reason ? $result->reason->value : 'invalid_code';
			if ( 'invalid_code' !== $reason ) {
				unset( $data['pending'][ $context ] );
				$this->session->save( $data );
			}

			return self::reason_failure( $reason );
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
	 */
	private function log_send( string $context, string $masked, string $error_code, string $error ): void {
		$this->log->insert(
			array(
				'recipient'     => $masked,
				'context'       => 'otp_' . $context,
				'status'        => '' === $error_code ? Message_Log::STATUS_OTP_SENT : Message_Log::STATUS_ERROR,
				'error_code'    => $error_code,
				'error_message' => $error,
				'test_mode'     => $this->settings->is_test_mode(),
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
	 * @param string      $masked     Numéro masqué.
	 * @param int         $expires_in Validité, en secondes.
	 * @param string|null $test_code  Code de test (administrateurs, mode test).
	 * @return array<string, mixed>
	 */
	private static function sent( string $masked, int $expires_in, ?string $test_code ): array {
		$response = array(
			'ok'         => true,
			'code'       => 'sent',
			/* translators: %s: masked phone number. */
			'message'    => sprintf( __( 'A code was sent by SMS to %s.', 'labyrinthe-connect' ), $masked ),
			'expires_in' => $expires_in,
			'resend_in'  => self::RESEND_HINT,
		);
		if ( null !== $test_code ) {
			$response['test_code'] = $test_code;
		}

		return $response;
	}

	/**
	 * Échec de vérification avec un motif de l'API.
	 *
	 * @param string $reason invalid_code, expired, already_used, max_attempts_reached.
	 * @return array<string, mixed>
	 */
	private static function reason_failure( string $reason ): array {
		switch ( $reason ) {
			case 'expired':
				return self::failure( $reason, __( 'This code has expired. Please request a new one.', 'labyrinthe-connect' ) );
			case 'already_used':
				return self::failure( $reason, __( 'This code was already used. Please request a new one.', 'labyrinthe-connect' ) );
			case 'max_attempts_reached':
				return self::failure( $reason, __( 'Too many wrong codes. Please request a new one.', 'labyrinthe-connect' ) );
			default:
				return self::failure( 'invalid_code', __( 'Wrong code. Please check the SMS and try again.', 'labyrinthe-connect' ) );
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
