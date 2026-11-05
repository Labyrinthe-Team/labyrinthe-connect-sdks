<?php
/**
 * Lien entre numéros de téléphone et comptes utilisateurs.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

use LabyrintheConnect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Le numéro de connexion d'un compte est la métadonnée « labyrinthe_phone » (format
 * international, 243812345678). Elle n'est remplie que par un numéro vérifié par OTP
 * (inscription, commande) ou par un administrateur : un utilisateur ne peut pas
 * s'attribuer le numéro d'un autre.
 */
final class Phone_Users {

	public const META = 'labyrinthe_phone';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Le compte qui peut se connecter avec ce numéro, ou null (aucun, plusieurs, ou non autorisé).
	 *
	 * @param string $phone Numéro normalisé.
	 */
	public function find_login_user( string $phone ): ?\WP_User {
		$ids = get_users(
			array(
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- recherche ponctuelle à la connexion.
				'meta_value'  => $phone, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- idem.
				'number'      => 2,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		// Un numéro partagé par plusieurs comptes ne permet pas de choisir : connexion refusée.
		$user = 1 === count( $ids ) ? get_user_by( 'id', (int) $ids[0] ) : false;

		/**
		 * Compte qui se connecte avec ce numéro (null : aucun).
		 *
		 * @param \WP_User|null $user  Compte trouvé.
		 * @param string        $phone Numéro (243812345678).
		 */
		$user = apply_filters( 'labyrinthe_connect_otp_login_user', $user instanceof \WP_User ? $user : null, $phone );

		return $user instanceof \WP_User && $this->may_log_in( $user ) ? $user : null;
	}

	/**
	 * Vrai si ce compte peut se connecter par SMS (les administrateurs seulement si l'option est active).
	 *
	 * @param \WP_User $user Compte.
	 */
	public function may_log_in( \WP_User $user ): bool {
		$allowed = ! user_can( $user, 'manage_options' ) || (bool) $this->settings->get( 'otp_allow_admins' );

		/**
		 * Autoriser (ou non) un compte à se connecter par SMS.
		 *
		 * @param bool     $allowed Décision par défaut.
		 * @param \WP_User $user    Compte.
		 */
		return (bool) apply_filters( 'labyrinthe_connect_otp_login_allowed', $allowed, $user );
	}

	/**
	 * Numéro de connexion d'un compte.
	 *
	 * @param int $user_id Compte.
	 */
	public static function get( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META, true );
	}

	/**
	 * Enregistre le numéro de connexion d'un compte (déjà normalisé et vérifié).
	 *
	 * @param int    $user_id Compte.
	 * @param string $phone   Numéro.
	 */
	public static function set( int $user_id, string $phone ): void {
		if ( '' === $phone ) {
			delete_user_meta( $user_id, self::META );

			return;
		}
		update_user_meta( $user_id, self::META, $phone );
	}
}
