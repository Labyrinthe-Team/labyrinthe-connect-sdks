<?php
/**
 * Création du client du SDK PHP à partir des réglages.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Http\Wp_Http_Client;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Client;
use LabyrintheConnect\Vendor\Psr\Http\Client\ClientInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique du client Labyrinthe Connect (SDK PHP préfixé).
 */
final class Client_Factory {

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
	 * Client configuré avec la clé API des réglages.
	 *
	 * @param float $timeout     Délai maximal d'une requête, en secondes.
	 * @param int   $max_retries Relances automatiques (uniquement quand c'est sans risque).
	 * @throws Configuration_Exception Aucune clé API configurée.
	 */
	public function make( float $timeout = 15.0, int $max_retries = 1 ): Client {
		$api_key = $this->settings->api_key();
		if ( '' === $api_key ) {
			throw new Configuration_Exception( esc_html__( 'No Labyrinthe Connect API key is configured.', 'labyrinthe-connect' ) );
		}

		/**
		 * Client HTTP PSR-18 utilisé par le SDK (par défaut : API HTTP de WordPress).
		 *
		 * @param ClientInterface $http_client Client HTTP (interface préfixée LabyrintheConnect\Vendor\Psr\Http\Client\ClientInterface).
		 */
		$http_client = apply_filters( 'labyrinthe_connect_http_client', new Wp_Http_Client( $timeout ) );
		if ( ! $http_client instanceof ClientInterface ) {
			$http_client = new Wp_Http_Client( $timeout );
		}

		return new Client(
			apiKey: $api_key,
			baseUrl: $this->base_url(),
			timeout: $timeout,
			maxRetries: max( 0, min( 3, $max_retries ) ),
			maxRetryWait: 3.0,
			defaultCountryCode: $this->settings->country_code(),
			defaultSender: $this->settings->sender(),
			httpClient: $http_client,
			userAgentSuffix: self::user_agent(),
		);
	}

	/**
	 * URL de l'API : https://api.labyrinthe-rdc.com par défaut ; constante
	 * LABYRINTHE_CONNECT_BASE_URL ou filtre labyrinthe_connect_base_url pour un autre environnement.
	 */
	public function base_url(): ?string {
		$url = defined( 'LABYRINTHE_CONNECT_BASE_URL' ) && is_string( LABYRINTHE_CONNECT_BASE_URL ) ? LABYRINTHE_CONNECT_BASE_URL : null;

		/**
		 * URL de l'API Labyrinthe Connect (null : production).
		 *
		 * @param string|null $url URL, par exemple http://localhost:8000.
		 */
		$url = apply_filters( 'labyrinthe_connect_base_url', $url );

		return is_string( $url ) && '' !== trim( $url ) ? trim( $url ) : null;
	}

	/**
	 * Suffixe du User-Agent : « labyrinthe-wordpress/1.0.0 WordPress/6.8 WooCommerce/10.0 ».
	 */
	public static function user_agent(): string {
		$user_agent = 'labyrinthe-wordpress/' . LABYRINTHE_CONNECT_VERSION . ' WordPress/' . get_bloginfo( 'version' );
		if ( defined( 'WC_VERSION' ) ) {
			$user_agent .= ' WooCommerce/' . WC_VERSION;
		}

		return $user_agent;
	}
}
