<?php
/**
 * Tables du plugin.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Création et mise à jour des tables (dbDelta).
 */
final class Schema {

	public const VERSION        = '2';
	public const VERSION_OPTION = 'labyrinthe_connect_db_version';

	/**
	 * Nom de la table du journal des SMS.
	 */
	public static function log_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'labyrinthe_sms_log';
	}

	/**
	 * Nom de la table des événements webhook reçus (dédoublonnage).
	 */
	public static function events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'labyrinthe_webhook_events';
	}

	/**
	 * Crée ou met à jour les tables si nécessaire.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Crée ou met à jour les tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$log     = self::log_table();
		$events  = self::events_table();

		// Le destinataire est enregistré masqué (24381*****78) : le journal ne contient pas de numéro complet.
		dbDelta(
			"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			message_id varchar(64) NOT NULL DEFAULT '',
			recipient varchar(32) NOT NULL DEFAULT '',
			context varchar(32) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT '',
			segments smallint(5) unsigned NOT NULL DEFAULT 0,
			encoding varchar(8) NOT NULL DEFAULT '',
			error_code varchar(64) NOT NULL DEFAULT '',
			error_message varchar(255) NOT NULL DEFAULT '',
			test_mode tinyint(1) NOT NULL DEFAULT 0,
			request_id varchar(128) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY message_id (message_id),
			KEY created_at (created_at),
			KEY order_id (order_id),
			KEY status (status)
			) {$charset};

			CREATE TABLE {$events} (
			event_id varchar(64) NOT NULL,
			event varchar(64) NOT NULL DEFAULT '',
			received_at datetime NOT NULL,
			PRIMARY KEY  (event_id),
			KEY received_at (received_at)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Supprime les tables (désinstallation).
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( array( self::log_table(), self::events_table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- tables du plugin.
		}
		delete_option( self::VERSION_OPTION );
	}
}
