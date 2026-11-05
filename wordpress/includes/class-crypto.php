<?php
/**
 * Chiffrement des secrets enregistrés en base (clé API, secret de webhook).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Chiffre les secrets avec libsodium (secretbox : XSalsa20-Poly1305, authentifié).
 *
 * La clé de chiffrement est dérivée des salts de WordPress (wp-config.php) : une copie
 * de la base seule ne suffit pas à lire la clé API. Si les salts changent, les secrets
 * deviennent illisibles et l'administrateur doit les saisir à nouveau (un avis le dit).
 * WordPress embarque sodium_compat : ces fonctions existent même sans l'extension sodium.
 */
final class Crypto {

	/** Préfixe de version du format chiffré. */
	public const PREFIX = 'lc1:';

	/**
	 * Chiffre une valeur ('' reste '').
	 *
	 * @param string $plain Valeur en clair.
	 */
	public static function encrypt( #[\SensitiveParameter] string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodage d'un binaire chiffré.
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Déchiffre une valeur ; '' si elle est vide, corrompue ou chiffrée avec d'autres salts.
	 *
	 * @param string $stored Valeur enregistrée.
	 */
	public static function decrypt( string $stored ): string {
		if ( ! self::is_encrypted( $stored ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- décodage d'un binaire chiffré.
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \SodiumException $e ) {
			return '';
		}

		return false === $plain ? '' : $plain;
	}

	/**
	 * Vrai pour une valeur au format chiffré du plugin.
	 *
	 * @param string $value Valeur.
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Clé de 32 octets dérivée des salts WordPress.
	 */
	private static function key(): string {
		return sodium_crypto_generichash( wp_salt( 'logged_in' ) . '|labyrinthe-connect|secrets', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
