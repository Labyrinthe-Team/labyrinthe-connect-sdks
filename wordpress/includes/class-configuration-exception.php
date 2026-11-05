<?php
/**
 * Plugin non configuré.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Levée quand une action demande une clé API absente.
 */
final class Configuration_Exception extends \RuntimeException {
}
