<?php
/**
 * Actions AJAX publiques du formulaire OTP.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

defined( 'ABSPATH' ) || exit;

/**
 * Actions admin-ajax.php labyrinthe_otp_send et labyrinthe_otp_verify (visiteurs et membres).
 * Nonce obligatoire ; seuls les contextes activés dans les réglages répondent.
 */
final class Ajax {

	public const NONCE = 'labyrinthe_otp';

	/**
	 * Service OTP.
	 *
	 * @var Otp_Service
	 */
	private Otp_Service $otp;

	/**
	 * Connexion par OTP.
	 *
	 * @var Login
	 */
	private Login $login;

	/**
	 * Constructeur.
	 *
	 * @param Otp_Service $otp   Service OTP.
	 * @param Login       $login Connexion par OTP.
	 */
	public function __construct( Otp_Service $otp, Login $login ) {
		$this->otp   = $otp;
		$this->login = $login;
	}

	/**
	 * Branche les actions.
	 */
	public function register(): void {
		foreach ( array( 'send', 'verify' ) as $action ) {
			add_action( 'wp_ajax_labyrinthe_otp_' . $action, array( $this, $action ) );
			add_action( 'wp_ajax_nopriv_labyrinthe_otp_' . $action, array( $this, $action ) );
		}
	}

	/**
	 * Envoi d'un code.
	 */
	public function send(): void {
		$context = $this->context();
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par context().

		$this->respond( $this->otp->send( $context, $phone ) );
	}

	/**
	 * Vérification d'un code (et connexion pour le contexte login).
	 */
	public function verify(): void {
		$context = $this->context();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié par context().
		$code     = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';
		$remember = ! empty( $_POST['remember'] );
		// phpcs:enable

		$result = $this->otp->verify( $context, $code );
		if ( $result['ok'] && Otp_Service::CONTEXT_LOGIN === $context ) {
			$result = $this->login->log_in( (int) $result['user_id'], $remember, $redirect );
		}
		unset( $result['phone'], $result['user_id'] );

		$this->respond( $result );
	}

	/**
	 * Vérifie le nonce et le contexte demandé.
	 */
	private function context(): string {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __( 'Your session has expired. Please reload the page.', 'labyrinthe-connect' ),
				),
				403
			);
		}

		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : '';
		if ( ! in_array( $context, $this->otp->enabled_contexts(), true ) ) {
			wp_send_json_error(
				array(
					'code'    => 'disabled',
					'message' => __( 'Verification by SMS is not available.', 'labyrinthe-connect' ),
				),
				400
			);
		}

		return $context;
	}

	/**
	 * Réponse JSON.
	 *
	 * @param array<string, mixed> $result Résultat.
	 */
	private function respond( array $result ): void {
		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		}
		$code = (string) ( $result['code'] ?? '' );
		wp_send_json_error( $result, in_array( $code, array( 'IP_RATE_LIMITED', 'OTP_RESEND_TOO_SOON' ), true ) ? 429 : 400 );
	}
}
