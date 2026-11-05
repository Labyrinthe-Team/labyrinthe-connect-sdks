<?php
/**
 * État OTP côté serveur, lié au navigateur par un cookie.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

defined( 'ABSPATH' ) || exit;

/**
 * Le navigateur ne reçoit qu'un jeton aléatoire (cookie httpOnly) ; otp_id, numéro et
 * état de vérification restent dans un transient côté serveur, dont la clé est
 * l'empreinte du jeton. otp_id n'est donc jamais exposé.
 */
final class Session {

	public const COOKIE = 'labyrinthe_otp';
	public const TTL    = HOUR_IN_SECONDS;

	/**
	 * Jeton de la requête en cours.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Données de la session.
	 *
	 * @return array{pending: array<string, array<string, mixed>>, verified: array<string, int>}
	 */
	public function get(): array {
		$empty = array(
			'pending'  => array(),
			'verified' => array(),
		);
		$token = $this->token( false );
		if ( null === $token ) {
			return $empty;
		}
		$data = get_transient( self::key( $token ) );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$data = array_merge( $empty, $data );
		// Vérifications expirées.
		$data['verified'] = array_filter( (array) $data['verified'], static fn( $expires ): bool => (int) $expires > time() );

		return $data;
	}

	/**
	 * Enregistre les données (crée le jeton si besoin).
	 *
	 * @param array{pending: array<string, array<string, mixed>>, verified: array<string, int>} $data Données.
	 */
	public function save( array $data ): void {
		$token = $this->token( true );
		if ( null !== $token ) {
			set_transient( self::key( $token ), $data, self::TTL );
		}
	}

	/**
	 * Jeton de session (cookie), créé si $create.
	 *
	 * @param bool $create Créer le jeton s'il n'existe pas.
	 */
	private function token( bool $create ): ?string {
		if ( null !== $this->token ) {
			return $this->token;
		}

		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $cookie ) ) {
			$this->token = $cookie;

			return $this->token;
		}
		if ( ! $create ) {
			return null;
		}

		$this->token             = bin2hex( random_bytes( 32 ) );
		$_COOKIE[ self::COOKIE ] = $this->token;
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$this->token,
				array(
					'expires'  => time() + self::TTL,
					'path'     => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		return $this->token;
	}

	/**
	 * Clé du transient d'un jeton.
	 *
	 * @param string $token Jeton.
	 */
	private static function key( string $token ): string {
		return 'labyrinthe_otp_' . hash( 'sha256', $token );
	}
}
