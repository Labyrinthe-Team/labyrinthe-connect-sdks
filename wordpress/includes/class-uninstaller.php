<?php
/**
 * Suppression de toutes les données du plugin.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Appelé par uninstall.php (suppression du plugin depuis l'administration) : options,
 * tables, transients, métadonnées des comptes et des commandes, tâches planifiées.
 * Ne dépend ni du SDK ni de WooCommerce (qui peut être désactivé à ce moment).
 */
final class Uninstaller {

	/** Options du plugin. */
	public const OPTIONS = array(
		'labyrinthe_connect_settings',
		'labyrinthe_connect_db_version',
		'labyrinthe_connect_balance_low',
		'labyrinthe_connect_last_ping',
		'labyrinthe_connect_account_suspended',
		'labyrinthe_connect_otp_shape',
	);

	/** Actions planifiées du plugin (Action Scheduler et WP-Cron). */
	public const HOOKS = array(
		'labyrinthe_connect_daily',
		'labyrinthe_connect_order_sms',
		'labyrinthe_connect_admin_order_sms',
	);

	/**
	 * Supprime les données de tous les sites (multisite) ou du site.
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::run_for_site();
				restore_current_blog();
			}

			return;
		}
		self::run_for_site();
	}

	/**
	 * Supprime les données du site courant.
	 */
	public static function run_for_site(): void {
		global $wpdb;

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		// Transients : session OTP, limites par IP, cache des Sender IDs.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_labyrinthe_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_labyrinthe_' ) . '%',
				$wpdb->esc_like( '_site_transient_labyrinthe_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_labyrinthe_' ) . '%'
			)
		);

		// Numéros de connexion des comptes.
		delete_metadata( 'user', 0, 'labyrinthe_phone', '', true );

		// Métadonnées des commandes (stockage classique et HPOS).
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $wpdb->esc_like( '_labyrinthe_' ) . '%' ) );
		if ( self::table_exists( $wpdb->prefix . 'wc_orders_meta' ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->prefix . 'wc_orders_meta', $wpdb->esc_like( '_labyrinthe_' ) . '%' ) );
		}

		// Actions planifiées.
		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
		// Action Scheduler sans WooCommerce actif (as_unschedule_all_actions() absente) : actions en attente supprimées.
		if ( self::table_exists( $wpdb->prefix . 'actionscheduler_actions' ) ) {
			foreach ( self::HOOKS as $hook ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE hook = %s AND status = 'pending'", $wpdb->prefix . 'actionscheduler_actions', $hook ) );
			}
		}
		// phpcs:enable

		Schema::drop();
	}

	/**
	 * Vrai si la table existe.
	 *
	 * @param string $table Nom complet.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- vérification ponctuelle.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
