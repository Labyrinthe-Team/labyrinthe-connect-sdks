<?php
/**
 * Point d'entrée du plugin.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Admin\Admin;
use LabyrintheConnect\Otp\Ajax;
use LabyrintheConnect\Otp\Checkout;
use LabyrintheConnect\Otp\Login;
use LabyrintheConnect\Otp\Otp_Service;
use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Otp\Rate_Limiter;
use LabyrintheConnect\Otp\Registration;
use LabyrintheConnect\Otp\Session;
use LabyrintheConnect\Webhook\Controller;
use LabyrintheConnect\Webhook\Event_Store;
use LabyrintheConnect\WooCommerce\Order_Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Assemble les services et branche les hooks.
 *
 * Aucune requête au backend n'est faite au chargement d'une page publique : l'API n'est
 * appelée que par une action AJAX (OTP, outils d'administration), une tâche planifiée
 * (SMS de commande) ou la page de réglages.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	public Settings $settings;

	/**
	 * Fabrique du client.
	 *
	 * @var Client_Factory
	 */
	public Client_Factory $clients;

	/**
	 * Journal.
	 *
	 * @var Message_Log
	 */
	public Message_Log $log;

	/**
	 * Envoi des SMS.
	 *
	 * @var Sms_Sender
	 */
	public Sms_Sender $sender;

	/**
	 * Service OTP.
	 *
	 * @var Otp_Service
	 */
	public Otp_Service $otp;

	/**
	 * Événements webhook reçus.
	 *
	 * @var Event_Store
	 */
	public Event_Store $events;

	/**
	 * Constructeur.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->clients  = new Client_Factory( $this->settings );
		$this->log      = new Message_Log();
		$this->sender   = new Sms_Sender( $this->settings, $this->clients, $this->log );
		$this->events   = new Event_Store();
		$this->otp      = new Otp_Service( $this->settings, $this->clients, new Session(), new Rate_Limiter(), new Phone_Users( $this->settings ), $this->log );
	}

	/**
	 * Instance unique.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Démarrage (plugins_loaded).
	 */
	public static function boot(): void {
		self::instance()->register_hooks();
	}

	/**
	 * Activation : tables et tâche quotidienne.
	 */
	public static function activate(): void {
		Schema::install();
		Cron::schedule();
	}

	/**
	 * Désactivation : la tâche quotidienne est retirée (les données restent jusqu'à la désinstallation).
	 */
	public static function deactivate(): void {
		Cron::unschedule();
	}

	/**
	 * Compatibilité WooCommerce : HPOS (tables de commandes) et validation de commande en blocs.
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LABYRINTHE_CONNECT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LABYRINTHE_CONNECT_FILE, true );
		}
	}

	/**
	 * Branche les hooks.
	 */
	private function register_hooks(): void {
		Schema::maybe_upgrade();

		add_action( Cron::HOOK, array( $this, 'daily' ) );
		add_action(
			'rest_api_init',
			function (): void {
				( new Controller( $this->settings, $this->log, $this->events ) )->register_routes();
			}
		);

		$users = new Phone_Users( $this->settings );
		$login = new Login( $this->settings, $users );
		$login->register();
		( new Ajax( $this->otp, $login ) )->register();
		( new Registration( $this->settings, $this->otp ) )->register();

		if ( class_exists( 'WooCommerce' ) ) {
			( new Order_Notifications( $this->settings, $this->sender ) )->register();
			( new Checkout( $this->settings, $this->otp ) )->register();
		}

		if ( is_admin() ) {
			( new Admin( $this->settings, $this->clients, $this->sender, new Sender_Ids( $this->clients ) ) )->register();
			add_action( 'admin_init', array( Cron::class, 'schedule' ) );
		}
	}

	/**
	 * Tâche quotidienne.
	 */
	public function daily(): void {
		Cron::run( $this->settings, $this->log, $this->events );
	}
}
