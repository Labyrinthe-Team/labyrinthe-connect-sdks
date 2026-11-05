<?php
/**
 * Plugin Name:          Labyrinthe Connect
 * Plugin URI:           https://labyrinthe-rdc.com/connect/wordpress
 * Description:          SMS and OTP for WordPress and WooCommerce: order notifications by SMS, phone verification and login with a code received by SMS (DR Congo and Central Africa).
 * Version:              1.0.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Author:               Labyrinthe Connect
 * Author URI:           https://labyrinthe-rdc.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          labyrinthe-connect
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package LabyrintheConnect
 */

// Ce fichier reste lisible par un vieux PHP (pas de typage ni de syntaxe récente) :
// sur un hébergement trop ancien, il affiche un avis au lieu d'une erreur fatale.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LABYRINTHE_CONNECT_VERSION', '1.0.0' );
define( 'LABYRINTHE_CONNECT_FILE', __FILE__ );
define( 'LABYRINTHE_CONNECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LABYRINTHE_CONNECT_URL', plugin_dir_url( __FILE__ ) );
define( 'LABYRINTHE_CONNECT_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, LABYRINTHE_CONNECT_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'labyrinthe_connect_php_notice' );
	return;
}

if ( ! is_file( LABYRINTHE_CONNECT_DIR . 'vendor-prefixed/autoload.php' ) ) {
	add_action( 'admin_notices', 'labyrinthe_connect_build_notice' );
	return;
}

require_once LABYRINTHE_CONNECT_DIR . 'vendor-prefixed/autoload.php';
require_once LABYRINTHE_CONNECT_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'LabyrintheConnect\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LabyrintheConnect\\Plugin', 'deactivate' ) );

// Compatibilité WooCommerce : tables de commandes HPOS et validation de commande en blocs.
add_action( 'before_woocommerce_init', array( 'LabyrintheConnect\\Plugin', 'declare_woocommerce_compatibility' ) );

add_action( 'plugins_loaded', array( 'LabyrintheConnect\\Plugin', 'boot' ) );

/**
 * Avis affiché quand la version de PHP est trop ancienne.
 *
 * @return void
 */
function labyrinthe_connect_php_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Labyrinthe Connect needs PHP %1$s or newer (this site runs PHP %2$s). Ask your host to upgrade PHP: the plugin stays inactive until then.', 'labyrinthe-connect' ),
				LABYRINTHE_CONNECT_MIN_PHP,
				PHP_VERSION
			)
		)
	);
}

/**
 * Avis affiché quand le plugin est installé depuis les sources sans dépendances.
 *
 * @return void
 */
function labyrinthe_connect_build_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Labyrinthe Connect is incomplete (vendor-prefixed/ is missing). Install the plugin from its zip file, or run "composer install" in its folder.', 'labyrinthe-connect' )
	);
}
