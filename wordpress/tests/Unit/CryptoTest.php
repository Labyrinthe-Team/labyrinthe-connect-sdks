<?php
/**
 * Chiffrement des secrets enregistrés en base.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Crypto;
use LabyrintheConnect\Tests\Support\TestCase;

final class CryptoTest extends TestCase {

	public function test_round_trip(): void {
		$encrypted = Crypto::encrypt( self::API_KEY );

		$this->assertStringStartsWith( Crypto::PREFIX, $encrypted );
		$this->assertStringNotContainsString( self::API_KEY, $encrypted );
		$this->assertSame( self::API_KEY, Crypto::decrypt( $encrypted ) );
	}

	public function test_each_encryption_uses_a_new_nonce(): void {
		$this->assertNotSame( Crypto::encrypt( 'secret' ), Crypto::encrypt( 'secret' ) );
	}

	public function test_empty_and_invalid_values(): void {
		$this->assertSame( '', Crypto::encrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( self::API_KEY ), 'A clear value is never accepted as encrypted.' );
		$this->assertSame( '', Crypto::decrypt( Crypto::PREFIX . 'not base64 !!' ) );
		$this->assertSame( '', Crypto::decrypt( Crypto::PREFIX . 'c2hvcnQ=' ) );
	}

	public function test_tampered_value_is_rejected(): void {
		$encrypted = Crypto::encrypt( self::API_KEY );
		$raw       = base64_decode( substr( $encrypted, strlen( Crypto::PREFIX ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw[30]   = chr( ord( $raw[30] ) ^ 1 );

		$this->assertSame( '', Crypto::decrypt( Crypto::PREFIX . base64_encode( $raw ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_value_encrypted_with_other_salts_cannot_be_read(): void {
		$encrypted = Crypto::encrypt( self::API_KEY );
		$salt      = static fn( string $salt, string $scheme ): string => 'logged_in' === $scheme ? 'other-salt' : $salt;
		add_filter( 'salt', $salt, 10, 2 );

		try {
			$this->assertSame( '', Crypto::decrypt( $encrypted ) );
		} finally {
			remove_filter( 'salt', $salt, 10 );
		}
		$this->assertSame( self::API_KEY, Crypto::decrypt( $encrypted ) );
	}
}
