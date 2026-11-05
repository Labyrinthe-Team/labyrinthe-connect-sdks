<?php
/**
 * Événements webhook déjà reçus (dédoublonnage sur l'identifiant).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Webhook;

use LabyrintheConnect\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Un même événement peut arriver plusieurs fois (jusqu'à 5 tentatives) : son `id` est
 * stable. La clé primaire rend la réservation atomique, même si deux livraisons
 * arrivent en même temps.
 */
final class Event_Store {

	/** Durée de conservation des identifiants, en jours (bien au-delà des 5 tentatives). */
	public const RETENTION_DAYS = 30;

	/**
	 * Réserve un événement. Faux s'il a déjà été reçu.
	 *
	 * @param string $event_id Identifiant de l'événement.
	 * @param string $event    Type (message.status_changed…).
	 */
	public function claim( string $event_id, string $event ): bool {
		global $wpdb;

		// INSERT IGNORE : n'insère rien (0 ligne) si l'identifiant existe déjà.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table du plugin.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (event_id, event, received_at) VALUES (%s, %s, %s)',
				Schema::events_table(),
				substr( $event_id, 0, 64 ),
				substr( $event, 0, 64 ),
				current_time( 'mysql', true )
			)
		);

		return 1 === (int) $inserted;
	}

	/**
	 * Libère un événement dont le traitement a échoué (la tentative suivante le retraitera).
	 *
	 * @param string $event_id Identifiant de l'événement.
	 */
	public function release( string $event_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table du plugin.
		$wpdb->delete( Schema::events_table(), array( 'event_id' => substr( $event_id, 0, 64 ) ), array( '%s' ) );
	}

	/**
	 * Supprime les identifiants anciens.
	 *
	 * @return int Lignes supprimées.
	 */
	public function purge(): int {
		global $wpdb;

		$limit = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table du plugin.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', Schema::events_table(), $limit ) );
	}
}
