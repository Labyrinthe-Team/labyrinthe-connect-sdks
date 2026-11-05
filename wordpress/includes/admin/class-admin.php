<?php
/**
 * Administration : menu, réglages, actions AJAX, avis.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Admin;

use LabyrintheConnect\Client_Factory;
use LabyrintheConnect\Configuration_Exception;
use LabyrintheConnect\Sender_Ids;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Sms_Sender;
use LabyrintheConnect\Sms_Text;
use LabyrintheConnect\Templates;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\ApiException;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Exception\LabyrintheException;
use LabyrintheConnect\Webhook\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Tout ce qui demande la capacité manage_options. Aucune action AJAX sans nonce.
 */
final class Admin {

	public const CAPABILITY = 'manage_options';
	public const SLUG       = 'labyrinthe-connect';
	public const LOG_SLUG   = 'labyrinthe-connect-log';
	public const NONCE      = 'labyrinthe_connect_admin';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Fabrique du client.
	 *
	 * @var Client_Factory
	 */
	private Client_Factory $clients;

	/**
	 * Envoi des SMS.
	 *
	 * @var Sms_Sender
	 */
	private Sms_Sender $sender;

	/**
	 * Sender IDs.
	 *
	 * @var Sender_Ids
	 */
	private Sender_Ids $sender_ids;

	/**
	 * Page de réglages.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $page;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings   Réglages.
	 * @param Client_Factory $clients    Fabrique du client.
	 * @param Sms_Sender     $sender     Envoi des SMS.
	 * @param Sender_Ids     $sender_ids Sender IDs.
	 */
	public function __construct( Settings $settings, Client_Factory $clients, Sms_Sender $sender, Sender_Ids $sender_ids ) {
		$this->settings   = $settings;
		$this->clients    = $clients;
		$this->sender     = $sender;
		$this->sender_ids = $sender_ids;
		$this->page       = new Settings_Page( $settings, $sender_ids );
	}

	/**
	 * Branche les hooks d'administration.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_labyrinthe_connect_dismiss_balance', array( $this, 'dismiss_balance' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LABYRINTHE_CONNECT_FILE ), array( $this, 'action_links' ) );

		add_action( 'wp_ajax_labyrinthe_connect_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_labyrinthe_connect_send_test_sms', array( $this, 'ajax_send_test_sms' ) );
		add_action( 'wp_ajax_labyrinthe_connect_refresh_senders', array( $this, 'ajax_refresh_senders' ) );

		( new User_Profile( $this->settings ) )->register();
	}

	/**
	 * Déclare l'option auprès de la Settings API (nonce et capacité gérés par options.php).
	 */
	public function register_setting(): void {
		register_setting(
			'labyrinthe_connect',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Texte suggéré pour la politique de confidentialité du site (Réglages > Confidentialité).
	 */
	public function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Labyrinthe Connect', 'labyrinthe-connect' ),
			wp_kses_post(
				'<p>' . __( 'When you place an order, create an account or log in with a code received by SMS, your phone number and the text of the SMS are sent to Labyrinthe Connect (labyrinthe-rdc.com), which delivers the SMS. This site keeps a log of the SMS sent, with masked phone numbers and without the texts, for a limited time.', 'labyrinthe-connect' ) . '</p>'
			)
		);
	}

	/**
	 * Menu « Labyrinthe Connect ».
	 */
	public function menu(): void {
		add_menu_page(
			__( 'Labyrinthe Connect', 'labyrinthe-connect' ),
			__( 'Labyrinthe Connect', 'labyrinthe-connect' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->page, 'render' ),
			'dashicons-smartphone',
			58
		);
		add_submenu_page( self::SLUG, __( 'Labyrinthe Connect settings', 'labyrinthe-connect' ), __( 'Settings', 'labyrinthe-connect' ), self::CAPABILITY, self::SLUG, array( $this->page, 'render' ) );
		add_submenu_page( self::SLUG, __( 'SMS log', 'labyrinthe-connect' ), __( 'SMS log', 'labyrinthe-connect' ), self::CAPABILITY, self::LOG_SLUG, array( new Log_Page(), 'render' ) );
	}

	/**
	 * Script et style des pages du plugin.
	 *
	 * @param string $hook Page d'administration.
	 */
	public function enqueue( $hook ): void {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'labyrinthe-connect-admin', LABYRINTHE_CONNECT_URL . 'assets/css/admin.css', array(), LABYRINTHE_CONNECT_VERSION );
		wp_enqueue_script( 'labyrinthe-connect-admin', LABYRINTHE_CONNECT_URL . 'assets/js/admin.js', array(), LABYRINTHE_CONNECT_VERSION, true );
		wp_localize_script(
			'labyrinthe-connect-admin',
			'labyrintheConnectAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'stripAccents' => $this->settings->strip_accents(),
				'samples'      => Templates::sample_values(),
				'i18n'         => array(
					'working'  => __( 'Please wait…', 'labyrinthe-connect' ),
					'error'    => __( 'Error', 'labyrinthe-connect' ),
					'copied'   => __( 'Copied', 'labyrinthe-connect' ),
					/* translators: 1: number of characters, 2: number of segments. */
					'gsm'      => __( '%1$d characters, GSM-7, %2$d SMS segment(s).', 'labyrinthe-connect' ),
					/* translators: 1: number of characters, 2: number of segments, 3: list of characters. */
					'unicode'  => __( '%1$d characters, Unicode (UCS-2), %2$d SMS segment(s): these characters make each SMS hold 70 characters instead of 160, often doubling the cost: %3$s', 'labyrinthe-connect' ),
					'stripped' => __( 'With "Remove accents" enabled, the SMS will be sent without accents.', 'labyrinthe-connect' ),
					'estimate' => __( 'Estimate with sample values; the API makes the final count.', 'labyrinthe-connect' ),
				),
			)
		);
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param array<int|string, string> $links Liens.
	 * @return array<int|string, string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'labyrinthe-connect' ) . '</a>' );

		return $links;
	}

	/**
	 * Avis : clé absente ou illisible, solde bas.
	 */
	public function notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_plugins = null !== $screen && ( 'plugins' === $screen->id || false !== strpos( (string) $screen->id, self::SLUG ) );
		$settings   = admin_url( 'admin.php?page=' . self::SLUG );

		if ( $this->settings->api_key_unreadable() ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Labyrinthe Connect: the saved API key can no longer be read (the security keys of wp-config.php have changed). Please enter it again.', 'labyrinthe-connect' ),
				esc_url( $settings ),
				esc_html__( 'Settings', 'labyrinthe-connect' )
			);
		} elseif ( $on_plugins && ! $this->settings->has_api_key() ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Labyrinthe Connect is installed: add your API key (client area > Developers > API keys) to start sending SMS.', 'labyrinthe-connect' ),
				esc_url( $settings ),
				esc_html__( 'Add the API key', 'labyrinthe-connect' )
			);
		}

		$low = get_option( Controller::BALANCE_LOW_OPTION );
		if ( is_array( $low ) && isset( $low['time'] ) && (int) $low['time'] > time() - 7 * DAY_IN_SECONDS ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: 1: credit balance, 2: low balance threshold. */
						__( 'Labyrinthe Connect: your SMS credit balance is low (%1$s credits, threshold %2$s). Buy credits in your client area so that SMS keep being sent.', 'labyrinthe-connect' ),
						number_format_i18n( (int) ( $low['balance'] ?? 0 ) ),
						number_format_i18n( (int) ( $low['threshold'] ?? 0 ) )
					)
				),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=labyrinthe_connect_dismiss_balance' ), 'labyrinthe_connect_dismiss_balance' ) ),
				esc_html__( 'Dismiss', 'labyrinthe-connect' )
			);
		}
	}

	/**
	 * Masque l'avis de solde bas.
	 */
	public function dismiss_balance(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'labyrinthe-connect' ), 403 );
		}
		check_admin_referer( 'labyrinthe_connect_dismiss_balance' );
		delete_option( Controller::BALANCE_LOW_OPTION );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * AJAX : « Tester la connexion » (lit le solde).
	 */
	public function ajax_test_connection(): void {
		$this->authorize();

		try {
			$balance = $this->clients->make( 15.0, 1 )->balance->get();
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}

		wp_send_json_success(
			array(
				'test_mode' => $this->settings->is_test_mode(),
				'balance'   => $balance->balance,
				'held'      => $balance->held,
				'message'   => sprintf(
					/* translators: 1: mode (test or live), 2: credit balance, 3: held credits. */
					__( 'Connection OK (%1$s). Balance: %2$s credits (%3$s held by campaigns in progress).', 'labyrinthe-connect' ),
					$this->settings->is_test_mode() ? __( 'test mode: nothing is sent, nothing is billed', 'labyrinthe-connect' ) : __( 'live mode', 'labyrinthe-connect' ),
					number_format_i18n( $balance->balance ),
					number_format_i18n( $balance->held )
				),
			)
		);
	}

	/**
	 * AJAX : « Envoyer un SMS de test ».
	 */
	public function ajax_send_test_sms(): void {
		$this->authorize();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié par authorize().
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$body  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		// phpcs:enable
		if ( '' === trim( $body ) ) {
			$body = Templates::test_message();
		}
		$body = Sms_Text::render( $body, array( '{shop_name}' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) );

		$result = $this->sender->send( $phone, $body, array( 'context' => 'test' ) );
		if ( ! $result->success ) {
			wp_send_json_error(
				array(
					'code'    => $result->error_code,
					/* translators: 1: error code, 2: error message. */
					'message' => sprintf( __( 'Not sent (%1$s): %2$s', 'labyrinthe-connect' ), $result->error_code, $result->error_message ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message_id' => $result->message_id,
				'message'    => sprintf(
					/* translators: 1: masked phone number, 2: message status, 3: number of segments, 4: message id. */
					__( 'SMS accepted for %1$s (status: %2$s, %3$d segment(s)). Message ID: %4$s', 'labyrinthe-connect' ),
					$result->recipient,
					$result->status,
					$result->segments,
					$result->message_id
				) . ( $result->test_mode ? ' ' . __( 'Test mode: nothing was actually sent.', 'labyrinthe-connect' ) : '' ),
			)
		);
	}

	/**
	 * AJAX : relit la liste des Sender IDs approuvés.
	 */
	public function ajax_refresh_senders(): void {
		$this->authorize();

		try {
			$senders = $this->sender_ids->refresh();
		} catch ( \Throwable $e ) {
			$this->fail( $e );
		}

		wp_send_json_success( array( 'senders' => $senders ) );
	}

	/**
	 * Capacité et nonce de toute action AJAX d'administration.
	 */
	private function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'labyrinthe-connect' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'labyrinthe-connect' ) ), 403 );
		}
	}

	/**
	 * Réponse d'erreur d'une action AJAX (sans jamais la clé API).
	 *
	 * @param \Throwable $e Erreur.
	 * @return never
	 */
	private function fail( \Throwable $e ): void {
		if ( $e instanceof ApiException ) {
			$code    = $e->getErrorCode();
			$message = 'UNAUTHENTICATED' === $code ? __( 'The API key was refused (invalid or revoked).', 'labyrinthe-connect' ) : $e->getMessage();
		} elseif ( $e instanceof Configuration_Exception ) {
			$code    = 'NOT_CONFIGURED';
			$message = __( 'Save an API key first.', 'labyrinthe-connect' );
		} elseif ( $e instanceof LabyrintheException && method_exists( $e, 'getErrorCode' ) ) {
			$code    = (string) $e->getErrorCode();
			$message = __( 'The Labyrinthe Connect API could not be reached.', 'labyrinthe-connect' );
		} else {
			$code    = 'ERROR';
			$message = __( 'Unexpected error.', 'labyrinthe-connect' );
		}

		wp_send_json_error(
			array(
				'code'    => $code,
				/* translators: 1: error code, 2: error message. */
				'message' => sprintf( __( 'Failed (%1$s): %2$s', 'labyrinthe-connect' ), $code, $message ),
			)
		);
	}
}
