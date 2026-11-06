<?php
/**
 * Encodage, segments et variables du texte d'un SMS.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Même règle que le backend (SegmentCalculator) : tout caractère hors ASCII fait passer
 * le message en UCS-2. GSM-7 : 160 caractères, 153 par segment au-delà ; UCS-2 : 70,
 * puis 67 par segment. Le backend reste l'autorité (POST /sms/estimate), y compris pour le
 * retrait des accents (strip_accents) : ce calcul ne sert que d'estimation de repli.
 */
final class Sms_Text {

	/**
	 * Analyse un texte.
	 *
	 * @param string $text Texte du SMS.
	 * @return array{encoding: string, characters: int, segments: int, unicode_characters: list<string>}
	 */
	public static function analyse( string $text ): array {
		$unicode    = self::needs_unicode( $text );
		$characters = mb_strlen( $text, 'UTF-8' );
		$single     = $unicode ? 70 : 160;
		$multi      = $unicode ? 67 : 153;

		if ( 0 === $characters ) {
			$segments = 0;
		} elseif ( $characters <= $single ) {
			$segments = 1;
		} else {
			$segments = (int) ceil( $characters / $multi );
		}

		return array(
			'encoding'           => $unicode ? 'ucs2' : 'gsm7',
			'characters'         => $characters,
			'segments'           => $segments,
			'unicode_characters' => self::unicode_characters( $text ),
		);
	}

	/**
	 * Vrai si le texte contient un caractère hors ASCII (accent, guillemet typographique, emoji…).
	 *
	 * @param string $text Texte.
	 */
	public static function needs_unicode( string $text ): bool {
		return 1 === preg_match( '/[^\x00-\x7F]/', $text );
	}

	/**
	 * Caractères qui font passer le message en Unicode (distincts, 10 au plus).
	 *
	 * @param string $text Texte.
	 * @return list<string>
	 */
	public static function unicode_characters( string $text ): array {
		if ( ! preg_match_all( '/[^\x00-\x7F]/u', $text, $matches ) ) {
			return array();
		}

		return array_slice( array_values( array_unique( $matches[0] ) ), 0, 10 );
	}

	/**
	 * Remplace les variables d'un modèle ({order_number}…). Les variables inconnues restent.
	 *
	 * @param string                $template  Modèle.
	 * @param array<string, string> $variables Variables (« {nom} » => valeur).
	 */
	public static function render( string $template, array $variables ): string {
		$text = strtr( $template, $variables );
		// Espaces multiples laissés par une variable vide ({tracking_url} sans suivi).
		$text = (string) preg_replace( '/[ \t]{2,}/', ' ', $text );

		return trim( $text );
	}
}
