<?php
/**
 * Limitation par adresse IP des demandes OTP.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

defined( 'ABSPATH' ) || exit;

/**
 * Fenêtre fixe par IP, en plus des limites du backend (par clé, par numéro, par compte) :
 * un robot ne peut pas épuiser les crédits du site en demandant des codes en boucle.
 */
final class Rate_Limiter {

	/**
	 * Enregistre une tentative ; faux si la limite est atteinte.
	 *
	 * @param string $action  Action (send, verify).
	 * @param int    $limit   Tentatives autorisées par fenêtre.
	 * @param int    $window  Durée de la fenêtre, en secondes.
	 */
	public function hit( string $action, int $limit, int $window ): bool {
		$key   = $this->key( $action );
		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || (int) ( $state['start'] ?? 0 ) + $window <= $now ) {
			$state = array(
				'start' => $now,
				'count' => 0,
			);
		}
		if ( (int) $state['count'] >= $limit ) {
			return false;
		}

		++$state['count'];
		set_transient( $key, $state, $window );

		return true;
	}

	/**
	 * Secondes avant la fin de la fenêtre en cours.
	 *
	 * @param string $action Action.
	 * @param int    $window Durée de la fenêtre.
	 */
	public function retry_after( string $action, int $window ): int {
		$state = get_transient( $this->key( $action ) );

		return is_array( $state ) ? max( 1, (int) $state['start'] + $window - time() ) : 0;
	}

	/**
	 * Adresse IP du visiteur. Derrière un proxy ou un CDN, utiliser le filtre
	 * labyrinthe_connect_client_ip (REMOTE_ADDR seul est fiable : les en-têtes
	 * X-Forwarded-For se falsifient).
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Adresse IP utilisée pour limiter les demandes de code.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'labyrinthe_connect_client_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Clé du transient.
	 *
	 * @param string $action Action.
	 */
	private function key( string $action ): string {
		return 'labyrinthe_rl_' . sanitize_key( $action ) . '_' . md5( self::client_ip() . '|' . wp_salt( 'nonce' ) );
	}
}
