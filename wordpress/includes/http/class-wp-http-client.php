<?php
/**
 * Client HTTP PSR-18 au-dessus de l'API HTTP de WordPress.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Http;

use LabyrintheConnect\Vendor\GuzzleHttp\Psr7\Response;
use LabyrintheConnect\Vendor\Psr\Http\Client\ClientInterface;
use LabyrintheConnect\Vendor\Psr\Http\Message\RequestInterface;
use LabyrintheConnect\Vendor\Psr\Http\Message\ResponseInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Le SDK passe par wp_remote_request() : le proxy, les certificats et les filtres
 * (pre_http_request, http_request_args) configurés dans WordPress s'appliquent, comme
 * pour toute autre extension.
 */
final class Wp_Http_Client implements ClientInterface {

	/**
	 * Délai maximal d'une requête, en secondes.
	 *
	 * @var float
	 */
	private float $timeout;

	/**
	 * Constructeur.
	 *
	 * @param float $timeout Délai maximal, en secondes.
	 */
	public function __construct( float $timeout = 15.0 ) {
		$this->timeout = $timeout;
	}

	/**
	 * Envoie une requête PSR-7.
	 *
	 * @param RequestInterface $request Requête.
	 * @throws Network_Exception Erreur réseau (DNS, TLS, délai…).
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- interface PSR-18.
		$headers = array();
		foreach ( array_keys( $request->getHeaders() ) as $name ) {
			$headers[ (string) $name ] = $request->getHeaderLine( (string) $name );
		}

		$body = (string) $request->getBody();
		$args = array(
			'method'      => $request->getMethod(),
			'headers'     => $headers,
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'httpversion' => '1.1',
			'user-agent'  => $request->getHeaderLine( 'User-Agent' ),
		);
		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( (string) $request->getUri(), $args );

		if ( is_wp_error( $response ) ) {
			throw new Network_Exception( $response->get_error_message(), $request ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- jamais affiché tel quel : le SDK en fait une ConnectionException.
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 100 || $status > 599 ) {
			throw new Network_Exception( 'Invalid HTTP response (no status code).', $request ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte fixe.
		}

		$response_headers = array();
		$raw_headers      = wp_remote_retrieve_headers( $response );
		$raw_headers      = is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ? $raw_headers->getAll() : (array) $raw_headers;
		foreach ( $raw_headers as $name => $value ) {
			$response_headers[ (string) $name ] = is_array( $value ) ? array_map( 'strval', $value ) : (string) $value;
		}

		return new Response(
			$status,
			$response_headers,
			wp_remote_retrieve_body( $response )
		);
	}
}
