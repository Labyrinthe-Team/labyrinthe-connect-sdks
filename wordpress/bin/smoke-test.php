<?php
/**
 * Test de fumée du zip construit : installe un vrai site WordPress (.wp-tests/site, tables
 * « lcsmoke_ » de la base de test), y copie WooCommerce et dist/labyrinthe-connect/,
 * active les deux extensions, vérifie le chargement (tables, route REST, bloc, compatibilité
 * HPOS déclarée), puis désinstalle le plugin et vérifie qu'il ne reste rien.
 *
 * Prérequis : php bin/install-wp-tests.php, php bin/build-zip.php
 * Usage     : php bin/smoke-test.php [--keep]   (--keep : garde le site pour Plugin Check)
 *
 * @package LabyrintheConnect
 */

// phpcs:disable -- outil en ligne de commande, hors WordPress.

$root = dirname( __DIR__ );
$keep = in_array( '--keep', $argv, true );

if ( isset( $argv[1] ) && '--step' === $argv[1] ) {
	run_step( (string) $argv[2], (string) $argv[3] );
	exit( 0 );
}

$tests  = $root . '/.wp-tests';
$site   = $tests . '/site';
$plugin = $root . '/dist/labyrinthe-connect';
foreach ( array( "$tests/wordpress/wp-load.php", "$tests/plugins/woocommerce/woocommerce.php", "$plugin/labyrinthe-connect.php", "$tests/wp-tests-config.php" ) as $required ) {
	if ( ! is_file( $required ) ) {
		fail( "Missing $required (run bin/install-wp-tests.php and bin/build-zip.php first)." );
	}
}

// Site : copie de WordPress, WooCommerce et du plugin construit.
if ( ! is_file( "$site/wp-load.php" ) ) {
	copy_tree( "$tests/wordpress", $site );
}
if ( ! is_file( "$site/wp-content/plugins/woocommerce/woocommerce.php" ) ) {
	copy_tree( "$tests/plugins/woocommerce", "$site/wp-content/plugins/woocommerce" );
}
remove_tree( "$site/wp-content/plugins/labyrinthe-connect" );
copy_tree( $plugin, "$site/wp-content/plugins/labyrinthe-connect" );

// Même base que la suite de tests, autre préfixe de tables.
$config = (string) file_get_contents( "$tests/wp-tests-config.php" );
$config = (string) preg_replace( "/define\( 'ABSPATH'.*\n/", '', $config );
$config = str_replace( "\$table_prefix = 'wptests_';", "\$table_prefix = 'lcsmoke_';", $config );
$config .= "define( 'WP_HOME', 'http://smoke.example.org' );\ndefine( 'WP_SITEURL', 'http://smoke.example.org' );\n";
$config .= "define( 'AUTH_KEY', 'smoke-a' ); define( 'SECURE_AUTH_KEY', 'smoke-b' ); define( 'LOGGED_IN_KEY', 'smoke-c' ); define( 'NONCE_KEY', 'smoke-d' );\n";
$config .= "define( 'AUTH_SALT', 'smoke-e' ); define( 'SECURE_AUTH_SALT', 'smoke-f' ); define( 'LOGGED_IN_SALT', 'smoke-g' ); define( 'NONCE_SALT', 'smoke-h' );\n";
$config .= "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
file_put_contents( "$site/wp-config.php", $config );

foreach ( array( 'install', 'activate', 'check', 'uninstall', 'cleanup' ) as $step ) {
	if ( 'uninstall' === $step && $keep ) {
		break;
	}
	$process = proc_open( array( PHP_BINARY, __FILE__, '--step', $step, $site ), array( STDIN, STDOUT, STDERR ), $pipes );
	$code    = is_resource( $process ) ? proc_close( $process ) : 1;
	if ( 0 !== $code ) {
		fail( "Step $step failed." );
	}
}

echo $keep ? "Smoke test OK (site kept in .wp-tests/site).\n" : "Smoke test OK: the zip installs, activates, loads and uninstalls cleanly.\n";

/**
 * Une étape, dans un processus PHP neuf (comme une requête WordPress).
 *
 * @param string $step Étape.
 * @param string $site Dossier du site.
 */
function run_step( string $step, string $site ): void {
	$_SERVER['HTTP_HOST']   = 'smoke.example.org';
	$_SERVER['REQUEST_URI'] = '/';
	if ( 'install' === $step ) {
		define( 'WP_INSTALLING', true );
	}
	if ( 'cleanup' === $step ) {
		// WordPress minimal : aucune extension chargée, rien ne tourne à l'arrêt du script.
		define( 'SHORTINIT', true );
		require $site . '/wp-load.php';
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( 'lcsmoke_' ) . '%' ) ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
		}
		step_ok( 'test site tables removed' );
		return;
	}
	require $site . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	global $wpdb;

	switch ( $step ) {
		case 'install':
			// Base propre : tables d'un précédent passage supprimées (préfixe lcsmoke_ uniquement).
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( 'lcsmoke_' ) . '%' ) ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
			}
			wp_install( 'Labyrinthe smoke test', 'admin', 'admin@example.org', false, '', wp_generate_password( 24 ) );
			step_ok( 'WordPress ' . get_bloginfo( 'version' ) . ' installed' );
			break;

		case 'activate':
			wp_set_current_user( 1 );
			foreach ( array( 'woocommerce/woocommerce.php', 'labyrinthe-connect/labyrinthe-connect.php' ) as $file ) {
				$result = activate_plugin( $file, '', false, false );
				if ( is_wp_error( $result ) ) {
					step_fail( "activate $file: " . $result->get_error_message() );
				}
			}
			step_ok( 'WooCommerce and Labyrinthe Connect activated' );
			break;

		case 'check':
			wp_set_current_user( 1 );
			if ( ! is_plugin_active( 'labyrinthe-connect/labyrinthe-connect.php' ) || ! class_exists( 'LabyrintheConnect\\Plugin' ) ) {
				step_fail( 'the plugin is not loaded' );
			}
			foreach ( array( 'labyrinthe_sms_log', 'labyrinthe_webhook_events' ) as $table ) {
				if ( $wpdb->prefix . $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) ) {
					step_fail( "table $table is missing" );
				}
			}
			if ( ! class_exists( 'LabyrintheConnect\\Vendor\\Labyrinthe\\Connect\\Client' ) || class_exists( 'Labyrinthe\\Connect\\Client' ) ) {
				step_fail( 'the SDK must be loaded from vendor-prefixed/ only' );
			}
			do_action( 'rest_api_init', rest_get_server() );
			if ( ! isset( rest_get_server()->get_routes()['/labyrinthe/v1/webhook'] ) ) {
				step_fail( 'REST route /labyrinthe/v1/webhook is missing' );
			}
			if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'labyrinthe-connect/otp-login' ) || ! shortcode_exists( 'labyrinthe_otp_login' ) ) {
				step_fail( 'block or shortcode is missing' );
			}
			$features   = wc_get_container()->get( Automattic\WooCommerce\Internal\Features\FeaturesController::class );
			$compatible = $features->get_compatible_plugins_for_feature( 'custom_order_tables', true );
			if ( ! in_array( 'labyrinthe-connect/labyrinthe-connect.php', $compatible['compatible'], true ) ) {
				step_fail( 'HPOS compatibility is not declared' );
			}
			$blocks = $features->get_compatible_plugins_for_feature( 'cart_checkout_blocks', true );
			if ( ! in_array( 'labyrinthe-connect/labyrinthe-connect.php', $blocks['compatible'], true ) ) {
				step_fail( 'block checkout compatibility is not declared' );
			}
			if ( false === wp_next_scheduled( 'labyrinthe_connect_daily' ) ) {
				step_fail( 'the daily purge is not scheduled' );
			}
			step_ok( 'loaded: tables, prefixed SDK, REST route, block, shortcode, HPOS + blocks compatibility, cron (WooCommerce ' . WC_VERSION . ', HPOS ' . ( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . ')' );
			break;

		case 'uninstall':
			wp_set_current_user( 1 );
			update_option( 'labyrinthe_connect_settings', array( 'sender' => 'X' ) );
			deactivate_plugins( 'labyrinthe-connect/labyrinthe-connect.php' );
			uninstall_plugin( 'labyrinthe-connect/labyrinthe-connect.php' );
			$left = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'labyrinthe_' ) . '%' ) );
			if ( array() !== $left || false !== get_option( 'labyrinthe_connect_settings' ) || false !== wp_next_scheduled( 'labyrinthe_connect_daily' ) ) {
				step_fail( 'data left after uninstall: ' . implode( ', ', $left ) );
			}
			step_ok( 'uninstalled: no table, option or scheduled task left' );
			break;
	}
}

function step_ok( string $message ): void {
	echo "  ok  $message\n";
}

function step_fail( string $message ): never {
	fwrite( STDERR, "  FAIL $message\n" );
	exit( 1 );
}

function fail( string $message ): never {
	fwrite( STDERR, "Smoke test failed: $message\n" );
	exit( 1 );
}

function copy_tree( string $from, string $to ): void {
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	if ( ! is_dir( $to ) ) {
		mkdir( $to, 0777, true );
	}
	foreach ( $items as $item ) {
		$target = $to . '/' . substr( $item->getPathname(), strlen( $from ) + 1 );
		$item->isDir() ? ( is_dir( $target ) || mkdir( $target, 0777, true ) ) : copy( $item->getPathname(), $target );
	}
}

function remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}
