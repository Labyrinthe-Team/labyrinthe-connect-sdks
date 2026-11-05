<?php
/**
 * Tâche quotidienne : purge du journal.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Webhook\Event_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Purge le journal des SMS (durée réglable) et les identifiants de webhooks reçus.
 */
final class Cron {

	public const HOOK = 'labyrinthe_connect_daily';

	/**
	 * Planifie la tâche si elle ne l'est pas.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Retire la tâche.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Exécute la purge.
	 *
	 * @param Settings    $settings Réglages.
	 * @param Message_Log $log      Journal.
	 * @param Event_Store $events   Événements webhook reçus.
	 * @return array{log: int, events: int} Lignes supprimées.
	 */
	public static function run( Settings $settings, Message_Log $log, Event_Store $events ): array {
		return array(
			'log'    => $log->purge( $settings->log_retention_days() ),
			'events' => $events->purge(),
		);
	}
}
