<?php
/**
 * Démarrage des tests : suite de tests WordPress (wp-phpunit), WooCommerce et le plugin.
 *
 * @package LabyrintheConnect
 */

$root = dirname( __DIR__ );

require_once $root . '/vendor/autoload.php';

$config = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
if ( false === $config || '' === $config ) {
	$config = $root . '/.wp-tests/wp-tests-config.php';
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $config ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- configuration de la suite de tests.
}
if ( ! is_file( $config ) ) {
	fwrite( STDERR, "The WordPress test configuration is missing: run php bin/install-wp-tests.php first.\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $root . '/vendor/yoast/phpunit-polyfills' );

$woocommerce = getenv( 'WC_PLUGIN_DIR' );
$woocommerce = false !== $woocommerce && '' !== $woocommerce ? $woocommerce : $root . '/.wp-tests/plugins/woocommerce';

require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root, $woocommerce ): void {
		require $woocommerce . '/woocommerce.php';
		require $root . '/labyrinthe-connect.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		// Tables et rôles de WooCommerce, comme dans la suite de tests de WooCommerce.
		if ( ! defined( 'WC_USE_TRANSACTIONS' ) ) {
			define( 'WC_USE_TRANSACTIONS', false );
		}
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
		LabyrintheConnect\Plugin::activate();
	}
);

// Fin d'installation de WooCommerce (activation de HPOS, comme sur un site neuf) avant les
// tests : sinon elle se ferait au premier admin_init, dans la transaction d'un test.
tests_add_filter(
	'init',
	static function (): void {
		if ( method_exists( 'WC_Install', 'newly_installed' ) ) {
			WC_Install::newly_installed();
		}
		// Les tables du cœur sont recréées à chaque lancement, pas celles de WooCommerce : on vide les
		// commandes d'un lancement précédent (base de test dédiée), sinon le stockage ne peut pas changer.
		global $wpdb;
		foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		// LABYRINTHE_TEST_HPOS=no : commandes dans les articles (stockage historique) au lieu de HPOS.
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' === getenv( 'LABYRINTHE_TEST_HPOS' ) ? 'no' : 'yes' );
	},
	1000
);

require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
