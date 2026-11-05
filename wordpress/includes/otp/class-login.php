<?php
/**
 * Connexion par code SMS : formulaire WordPress, compte WooCommerce, shortcode et bloc.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

use LabyrintheConnect\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Connexion par OTP.
 */
final class Login {

	public const SHORTCODE = 'labyrinthe_otp_login';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Comptes par numéro.
	 *
	 * @var Phone_Users
	 */
	private Phone_Users $users;

	/**
	 * Constructeur.
	 *
	 * @param Settings    $settings Réglages.
	 * @param Phone_Users $users    Comptes par numéro.
	 */
	public function __construct( Settings $settings, Phone_Users $users ) {
		$this->settings = $settings;
		$this->users    = $users;
	}

	/**
	 * Branche le shortcode, le bloc et les formulaires de connexion.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );

		if ( $this->enabled() && $this->settings->get( 'otp_login_form' ) ) {
			add_action( 'login_form', array( $this, 'login_form' ) );
			add_action( 'woocommerce_login_form_end', array( $this, 'woocommerce_login_form' ) );
		}
	}

	/**
	 * Connexion par SMS activée (et clé API présente).
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'otp_login_enabled' ) && $this->settings->has_api_key();
	}

	/**
	 * Formulaire de connexion de WordPress (wp-login.php).
	 */
	public function login_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de la destination.
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';

		$html = Widget::render(
			Otp_Service::CONTEXT_LOGIN,
			array(
				'redirect'  => $redirect,
				'collapsed' => true,
				'remember'  => true,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML échappé dans Widget::render().
	}

	/**
	 * Formulaire de connexion de « Mon compte » (WooCommerce).
	 */
	public function woocommerce_login_form(): void {
		$redirect = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

		$html = Widget::render(
			Otp_Service::CONTEXT_LOGIN,
			array(
				'redirect'  => $redirect,
				'collapsed' => true,
				'remember'  => true,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML échappé dans Widget::render().
	}

	/**
	 * Shortcode [labyrinthe_otp_login redirect="/mon-compte/" title="…"].
	 *
	 * @param array<string, string>|string $atts Attributs.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'redirect' => '',
				'title'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		if ( ! $this->enabled() ) {
			return current_user_can( 'manage_options' )
				? '<p class="labyrinthe-otp-notice">' . esc_html__( 'SMS login is disabled: enable it in Labyrinthe Connect > Settings > OTP.', 'labyrinthe-connect' ) . '</p>'
				: '';
		}
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			return '<p class="labyrinthe-otp-notice">' . esc_html__( 'You are already logged in.', 'labyrinthe-connect' ) . '</p>';
		}

		$args = array(
			'redirect' => '' !== $atts['redirect'] ? $atts['redirect'] : home_url( '/' ),
			'remember' => true,
		);
		if ( '' !== $atts['title'] ) {
			$args['title'] = sanitize_text_field( $atts['title'] );
		}

		return Widget::render( Otp_Service::CONTEXT_LOGIN, $args );
	}

	/**
	 * Bloc Gutenberg « Connexion par SMS » (rendu serveur, même formulaire que le shortcode).
	 */
	public function register_block(): void {
		register_block_type(
			LABYRINTHE_CONNECT_DIR . 'blocks/otp-login',
			array(
				'render_callback' => function ( $attributes ): string {
					$attributes = is_array( $attributes ) ? $attributes : array();

					return $this->shortcode(
						array(
							'redirect' => isset( $attributes['redirect'] ) ? (string) $attributes['redirect'] : '',
							'title'    => isset( $attributes['title'] ) ? (string) $attributes['title'] : '',
						)
					);
				},
			)
		);
		wp_set_script_translations( 'labyrinthe-connect-otp-login-editor-script', 'labyrinthe-connect', LABYRINTHE_CONNECT_DIR . 'languages' );
	}

	/**
	 * Connecte le compte dont le numéro vient d'être vérifié.
	 *
	 * @param int    $user_id  Compte (0 si aucun : ne devrait pas arriver après une vérification réelle).
	 * @param bool   $remember Rester connecté.
	 * @param string $redirect Destination demandée.
	 * @return array<string, mixed>
	 */
	public function log_in( int $user_id, bool $remember, string $redirect ): array {
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user instanceof \WP_User || ! $this->users->may_log_in( $user ) ) {
			return array(
				'ok'      => false,
				'code'    => 'login_failed',
				'message' => __( 'This account cannot log in by SMS.', 'labyrinthe-connect' ),
			);
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		/** Cette action est documentée dans wp-includes/user.php. */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- action du cœur.

		$default  = user_can( $user, 'edit_posts' ) ? admin_url() : home_url( '/' );
		$redirect = wp_validate_redirect( '' !== $redirect ? $redirect : $default, $default );
		/** Ce filtre est documenté dans wp-login.php. */
		$redirect = (string) apply_filters( 'login_redirect', $redirect, $redirect, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.

		return array(
			'ok'       => true,
			'code'     => 'logged_in',
			'message'  => __( 'You are logged in.', 'labyrinthe-connect' ),
			'redirect' => wp_validate_redirect( $redirect, $default ),
		);
	}
}
