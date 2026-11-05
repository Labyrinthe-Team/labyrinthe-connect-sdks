<?php
/**
 * Numéros de téléphone.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Vendor\Labyrinthe\Connect\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/**
 * Normalisation identique à l'API (SDK PHP), avec l'indicatif du pays de facturation
 * WooCommerce pour les numéros au format local.
 */
final class Phone {

	/**
	 * Numéro international sans « + » (243812345678), ou null s'il est invalide.
	 *
	 * @param string      $raw          Numéro saisi (+243 81…, 0812345678…).
	 * @param string      $country_code Indicatif par défaut (réglages).
	 * @param string|null $country_iso  Pays (code ISO, ex. « CD ») dont l'indicatif s'applique aux numéros locaux.
	 */
	public static function normalize( string $raw, string $country_code, ?string $country_iso = null ): ?string {
		$code = self::calling_code( $country_iso ) ?? $country_code;

		try {
			return PhoneNumber::normalize( $raw, $code );
		} catch ( \Throwable $e ) {
			return PhoneNumber::normalize( $raw, PhoneNumber::DEFAULT_COUNTRY_CODE );
		}
	}

	/**
	 * Numéro masqué pour l'affichage et le journal : 24381*****78.
	 *
	 * @param string $phone Numéro.
	 */
	public static function mask( string $phone ): string {
		return PhoneNumber::mask( $phone );
	}

	/**
	 * Indicatif d'un pays d'après WooCommerce (« CD » → « 243 »), ou null.
	 *
	 * @param string|null $country_iso Code ISO du pays.
	 */
	private static function calling_code( ?string $country_iso ): ?string {
		if ( null === $country_iso || '' === $country_iso || ! function_exists( 'WC' ) ) {
			return null;
		}
		$countries = WC()->countries;
		if ( ! is_object( $countries ) || ! method_exists( $countries, 'get_country_calling_code' ) ) {
			return null;
		}
		$code = $countries->get_country_calling_code( $country_iso );
		$code = is_array( $code ) ? (string) reset( $code ) : (string) $code;
		$code = (string) preg_replace( '/\D/', '', $code );

		return 1 === preg_match( '/^[1-9]\d{0,2}$/', $code ) ? $code : null;
	}
}
