<?php
/**
 * Encodage, segments, retrait des accents, variables.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Sms_Text;
use LabyrintheConnect\Tests\Support\TestCase;

final class SmsTextTest extends TestCase {

	/**
	 * Mêmes limites que le backend (SegmentCalculator).
	 *
	 * @return array<string, array{string, string, int}>
	 */
	public function provide_texts(): array {
		return array(
			'empty'            => array( '', 'gsm7', 0 ),
			'gsm 160'          => array( str_repeat( 'a', 160 ), 'gsm7', 1 ),
			'gsm 161'          => array( str_repeat( 'a', 161 ), 'gsm7', 2 ),
			'gsm 306'          => array( str_repeat( 'a', 306 ), 'gsm7', 2 ),
			'gsm 307'          => array( str_repeat( 'a', 307 ), 'gsm7', 3 ),
			'accent 70'        => array( 'é' . str_repeat( 'a', 69 ), 'ucs2', 1 ),
			'accent 71'        => array( 'é' . str_repeat( 'a', 70 ), 'ucs2', 2 ),
			'accent 134'       => array( 'é' . str_repeat( 'a', 133 ), 'ucs2', 2 ),
			'accent 135'       => array( 'é' . str_repeat( 'a', 134 ), 'ucs2', 3 ),
			'curly apostrophe' => array( 'C’est prêt', 'ucs2', 1 ),
			'ascii braces'     => array( 'Code {1234} [ok]', 'gsm7', 1 ),
		);
	}

	/**
	 * @dataProvider provide_texts
	 */
	public function test_analyse( string $text, string $encoding, int $segments ): void {
		$result = Sms_Text::analyse( $text );

		$this->assertSame( $encoding, $result['encoding'] );
		$this->assertSame( $segments, $result['segments'] );
		$this->assertSame( mb_strlen( $text ), $result['characters'] );
	}

	public function test_unicode_characters_are_listed(): void {
		$this->assertSame( array( 'É', 'é', '’' ), Sms_Text::analyse( 'Été l’an' )['unicode_characters'] );
		$this->assertSame( array( 'É', '’' ), Sms_Text::unicode_characters( 'Ét l’a' ) );
	}

	public function test_strip_special_characters_keeps_the_sms_in_gsm7(): void {
		$stripped = Sms_Text::strip_special_characters( "Votre commande « n°1042 » est expédiée — merci ! C’est à 25\u{00A0}€…" );

		$this->assertSame( 'Votre commande " no1042 " est expediee - merci ! C\'est a 25 EUR...', $stripped );
		$this->assertSame( 'gsm7', Sms_Text::analyse( $stripped )['encoding'] );
	}

	public function test_render_replaces_variables_and_cleans_spaces(): void {
		$text = Sms_Text::render(
			'Bonjour {customer_name}, commande {order_number} {tracking_url} {unknown}',
			array(
				'{customer_name}' => 'Marie',
				'{order_number}'  => '1042',
				'{tracking_url}'  => '',
			)
		);

		$this->assertSame( 'Bonjour Marie, commande 1042 {unknown}', $text );
	}
}
