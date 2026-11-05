<?php
/**
 * Désinstallation : supprime toutes les données de Labyrinthe Connect.
 *
 * @package LabyrintheConnect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/autoload.php';

LabyrintheConnect\Uninstaller::run();
