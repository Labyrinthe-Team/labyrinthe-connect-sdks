<?php
/**
 * Classes de la Store API de WooCommerce absentes de php-stubs/woocommerce-stubs (PHPStan seulement).
 *
 * @package LabyrintheConnect
 */

namespace Automattic\WooCommerce\StoreApi\Exceptions;

/**
 * Erreur renvoyée par une route de la Store API (validation de commande en blocs).
 */
class RouteException extends \Exception {

	/**
	 * Constructeur.
	 *
	 * @param string       $error_code       Code d'erreur.
	 * @param string       $message          Message.
	 * @param int          $http_status_code Statut HTTP.
	 * @param array<mixed> $additional_data  Données.
	 */
	public function __construct( $error_code, $message, $http_status_code = 400, $additional_data = array() ) {
		parent::__construct( $message, $http_status_code );
	}
}
