<?php
/**
 * État du compte Labyrinthe Connect vu par le plugin (compte suspendu).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\AccountSuspendedException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ApiException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\FeatureDisabledException;

defined( 'ABSPATH' ) || exit;

/**
 * Mémorise un refus 403 ACCOUNT_SUSPENDED (toute l'API est fermée jusqu'à la réactivation
 * du compte) pour afficher un avis clair dans l'administration ; effacé au premier appel réussi.
 * Rédige aussi le message d'une maintenance de la plateforme (503 FEATURE_DISABLED).
 */
final class Account_Status {

	public const SUSPENDED_OPTION = 'labyrinthe_connect_account_suspended';

	/**
	 * À appeler après une erreur de l'API : mémorise la suspension du compte.
	 *
	 * @param \Throwable $e Erreur.
	 */
	public static function record_error( \Throwable $e ): void {
		if ( ! self::is_suspension( $e ) ) {
			return;
		}
		update_option(
			self::SUSPENDED_OPTION,
			array(
				'time'       => time(),
				'request_id' => $e instanceof ApiException ? (string) $e->getRequestId() : '',
			),
			false
		);
	}

	/**
	 * À appeler après un appel réussi : le compte n'est plus suspendu.
	 */
	public static function record_success(): void {
		if ( false !== get_option( self::SUSPENDED_OPTION, false ) ) {
			delete_option( self::SUSPENDED_OPTION );
		}
	}

	/**
	 * Suspension constatée, ou null.
	 *
	 * @return array{time: int, request_id: string}|null
	 */
	public static function suspended(): ?array {
		$value = get_option( self::SUSPENDED_OPTION, false );
		if ( ! is_array( $value ) ) {
			return null;
		}

		return array(
			'time'       => (int) ( $value['time'] ?? 0 ),
			'request_id' => (string) ( $value['request_id'] ?? '' ),
		);
	}

	/**
	 * Vrai pour un refus 403 ACCOUNT_SUSPENDED.
	 *
	 * @param \Throwable $e Erreur.
	 */
	public static function is_suspension( \Throwable $e ): bool {
		return $e instanceof AccountSuspendedException || ( $e instanceof ApiException && 'ACCOUNT_SUSPENDED' === $e->getErrorCode() );
	}

	/**
	 * Message d'une fonctionnalité coupée par la plateforme (503 FEATURE_DISABLED ou
	 * OTP_SERVICE_DISABLED) : celui de l'administrateur de la plateforme s'il en a saisi un
	 * (texte brut : balises retirées), sinon le message générique traduit, suivi de l'heure de
	 * retour quand une réactivation automatique est programmée.
	 *
	 * @param FeatureDisabledException $e       Erreur.
	 * @param string                   $generic Message générique (traduit).
	 */
	public static function maintenance_message( FeatureDisabledException $e, string $generic ): string {
		$admin   = $e->getAdminMessage();
		$admin   = null !== $admin ? sanitize_text_field( $admin ) : '';
		$message = '' !== $admin ? $admin : $generic;

		$until = $e->getUntil();
		if ( null !== $until && $until->getTimestamp() > time() ) {
			$format = trim( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ) );
			$back   = wp_date( '' !== $format ? $format : 'Y-m-d H:i', $until->getTimestamp() );
			if ( false !== $back ) {
				/* translators: %s: date and time when the service should be available again. */
				$message .= ' ' . sprintf( __( 'Expected back: %s.', 'labyrinthe-connect' ), $back );
			}
		}

		return $message;
	}

	/**
	 * Identifiant de requête à communiquer au support : celui renvoyé par l'API, sinon
	 * celui envoyé par le plugin (aussi utile pour une erreur réseau).
	 *
	 * @param \Throwable $e        Erreur.
	 * @param string     $fallback Identifiant envoyé avec la requête.
	 */
	public static function request_id( \Throwable $e, string $fallback = '' ): string {
		$id = $e instanceof ApiException ? $e->getRequestId() : null;

		return null !== $id && '' !== $id ? $id : $fallback;
	}

	/**
	 * Nouvel identifiant de requête (X-Request-Id), propre au site : « wp-<uuid> ».
	 */
	public static function new_request_id(): string {
		return 'wp-' . wp_generate_uuid4();
	}
}
