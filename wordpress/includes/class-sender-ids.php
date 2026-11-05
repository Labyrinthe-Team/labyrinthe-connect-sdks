<?php
/**
 * Sender IDs approuvés du compte (liste en cache).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * La liste n'est lue qu'en administration (page de réglages) et gardée une heure.
 */
final class Sender_Ids {

	public const CACHE_KEY = 'labyrinthe_connect_sender_ids';

	/**
	 * Fabrique du client.
	 *
	 * @var Client_Factory
	 */
	private Client_Factory $clients;

	/**
	 * Constructeur.
	 *
	 * @param Client_Factory $clients Fabrique du client.
	 */
	public function __construct( Client_Factory $clients ) {
		$this->clients = $clients;
	}

	/**
	 * Liste en cache, ou null si elle n'a pas encore été lue.
	 *
	 * @return list<array{value: string, is_default: bool}>|null
	 */
	public function cached(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		return is_array( $cached ) ? array_values( $cached ) : null;
	}

	/**
	 * Relit la liste depuis l'API.
	 *
	 * @return list<array{value: string, is_default: bool}>
	 * @throws Configuration_Exception Pas de clé API.
	 * @throws \LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\LabyrintheException Erreur de l'API.
	 */
	public function refresh(): array {
		$list = array();
		foreach ( $this->clients->make()->senderIds->list() as $sender ) {
			$list[] = array(
				'value'      => $sender->value,
				'is_default' => $sender->isDefault, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
			);
		}
		set_transient( self::CACHE_KEY, $list, HOUR_IN_SECONDS );

		return $list;
	}
}
