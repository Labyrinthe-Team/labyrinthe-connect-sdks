<?php
/**
 * État du compte Labyrinthe Connect vu par le plugin (compte suspendu).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\AccountSuspendedException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ApiException;

defined( 'ABSPATH' ) || exit;

/**
 * Mémorise un refus 403 ACCOUNT_SUSPENDED (toute l'API est fermée jusqu'à la réactivation
 * du compte) pour afficher un avis clair dans l'administration ; effacé au premier appel réussi.
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
