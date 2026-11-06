<?php
/**
 * Formulaire OTP (numéro, puis code) affiché sur le site.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Otp;

defined( 'ABSPATH' ) || exit;

/**
 * Rendu HTML du formulaire OTP et chargement de son script. Le script parle à
 * admin-ajax.php (actions labyrinthe_otp_send et labyrinthe_otp_verify) avec un nonce ;
 * il ne reçoit jamais otp_id.
 */
final class Widget {

	public const SCRIPT = 'labyrinthe-connect-otp';

	/**
	 * Compteur d'identifiants HTML uniques.
	 *
	 * @var int
	 */
	private static int $count = 0;

	/**
	 * Rendu du formulaire.
	 *
	 * @param string                                                                                                                  $context Contexte (login, register, checkout).
	 * @param array{redirect?: string, phone_source?: string, field_name?: string, title?: string, collapsed?: bool, remember?: bool} $args Options.
	 */
	public static function render( string $context, array $args = array() ): string {
		self::enqueue();

		$id     = 'labyrinthe-otp-' . ( ++self::$count );
		$source = $args['phone_source'] ?? '';
		$name   = $args['field_name'] ?? '';

		$titles = array(
			Otp_Service::CONTEXT_LOGIN    => __( 'Log in with a code received by SMS', 'labyrinthe-connect' ),
			Otp_Service::CONTEXT_REGISTER => __( 'Verify your phone number', 'labyrinthe-connect' ),
			Otp_Service::CONTEXT_CHECKOUT => __( 'Verify your phone number to place the order', 'labyrinthe-connect' ),
		);
		$title  = $args['title'] ?? ( $titles[ $context ] ?? '' );
		// Forme du code (longueur, chiffres ou lettres) : celle du dernier envoi, mise à jour par le script après l'envoi.
		$shape = Otp_Service::shape();

		ob_start();
		?>
		<div class="labyrinthe-otp<?php echo ! empty( $args['collapsed'] ) ? ' labyrinthe-otp--collapsed' : ''; ?>" id="<?php echo esc_attr( $id ); ?>"
			data-context="<?php echo esc_attr( $context ); ?>"
			data-redirect="<?php echo esc_url( $args['redirect'] ?? '' ); ?>"
			data-phone-source="<?php echo esc_attr( $source ); ?>">
			<?php if ( ! empty( $args['collapsed'] ) ) : ?>
				<p class="labyrinthe-otp__toggle-wrap">
					<button type="button" class="button-link labyrinthe-otp__toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-body"><?php echo esc_html( $title ); ?></button>
				</p>
			<?php elseif ( '' !== $title ) : ?>
				<p class="labyrinthe-otp__title"><strong><?php echo esc_html( $title ); ?></strong></p>
			<?php endif; ?>
			<div class="labyrinthe-otp__body" id="<?php echo esc_attr( $id ); ?>-body"<?php echo ! empty( $args['collapsed'] ) ? ' hidden' : ''; ?>>
				<div class="labyrinthe-otp__step labyrinthe-otp__step--phone"<?php echo '' !== $source ? ' hidden' : ''; ?>>
					<label for="<?php echo esc_attr( $id ); ?>-phone"><?php esc_html_e( 'Phone number', 'labyrinthe-connect' ); ?></label>
					<input type="tel" class="input labyrinthe-otp__phone" id="<?php echo esc_attr( $id ); ?>-phone" autocomplete="tel" inputmode="tel" placeholder="0812345678"<?php echo '' !== $name ? ' name="' . esc_attr( $name ) . '"' : ''; ?> />
				</div>
				<p class="labyrinthe-otp__actions">
					<button type="button" class="button labyrinthe-otp__send"><?php esc_html_e( 'Send me a code', 'labyrinthe-connect' ); ?></button>
				</p>
				<div class="labyrinthe-otp__step labyrinthe-otp__step--code" hidden>
					<label for="<?php echo esc_attr( $id ); ?>-code"><?php esc_html_e( 'Code received by SMS', 'labyrinthe-connect' ); ?></label>
					<input type="text" class="input labyrinthe-otp__code" id="<?php echo esc_attr( $id ); ?>-code" autocomplete="one-time-code" spellcheck="false" <?php echo self::code_attributes( $shape['code_length'], $shape['code_type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs échappés par code_attributes(). ?> />
					<?php if ( ! empty( $args['remember'] ) ) : ?>
						<label class="labyrinthe-otp__remember"><input type="checkbox" class="labyrinthe-otp__remember-input" value="1" /> <?php esc_html_e( 'Remember me', 'labyrinthe-connect' ); ?></label>
					<?php endif; ?>
					<p class="labyrinthe-otp__actions">
						<button type="button" class="button button-primary labyrinthe-otp__verify"><?php echo esc_html( Otp_Service::CONTEXT_LOGIN === $context ? __( 'Log in', 'labyrinthe-connect' ) : __( 'Verify', 'labyrinthe-connect' ) ); ?></button>
						<button type="button" class="button-link labyrinthe-otp__resend" disabled><?php esc_html_e( 'Send a new code', 'labyrinthe-connect' ); ?> <span class="labyrinthe-otp__countdown"></span></button>
					</p>
				</div>
				<p class="labyrinthe-otp__message" role="status" aria-live="polite"></p>
				<p class="labyrinthe-otp__test-code" hidden></p>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Attributs du champ de code selon sa forme : longueur, clavier numérique ou texte.
	 *
	 * Pas d'attribut pattern ni required : le champ est souvent dans le formulaire d'inscription
	 * ou de commande, dont il bloquerait l'envoi (validation HTML) une fois le numéro vérifié.
	 *
	 * @param int    $length Nombre de caractères du code.
	 * @param string $type   numeric ou alphanumeric.
	 */
	public static function code_attributes( int $length, string $type ): string {
		$numeric = 'alphanumeric' !== $type;
		$length  = max( 4, min( 8, $length ) );

		return sprintf(
			'inputmode="%1$s" maxlength="%2$d" size="%2$d" autocapitalize="%3$s" data-code-length="%2$d" data-code-type="%4$s"',
			$numeric ? 'numeric' : 'text',
			$length,
			$numeric ? 'off' : 'characters',
			$numeric ? 'numeric' : 'alphanumeric'
		);
	}

	/**
	 * Charge le script et la feuille de style du formulaire.
	 */
	public static function enqueue(): void {
		if ( ! wp_script_is( self::SCRIPT, 'registered' ) ) {
			wp_register_script( self::SCRIPT, LABYRINTHE_CONNECT_URL . 'assets/js/otp.js', array(), LABYRINTHE_CONNECT_VERSION, true );
			wp_register_style( self::SCRIPT, LABYRINTHE_CONNECT_URL . 'assets/css/otp.css', array(), LABYRINTHE_CONNECT_VERSION );
			wp_localize_script(
				self::SCRIPT,
				'labyrintheConnectOtp',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Ajax::NONCE ),
					'i18n'    => array(
						'sending'      => __( 'Sending…', 'labyrinthe-connect' ),
						'checking'     => __( 'Checking…', 'labyrinthe-connect' ),
						'phoneMissing' => __( 'Please enter your phone number first.', 'labyrinthe-connect' ),
						'codeMissing'  => __( 'Please enter the code received by SMS.', 'labyrinthe-connect' ),
						/* translators: %d: number of seconds. */
						'countdown'    => __( '(%ds)', 'labyrinthe-connect' ),
						/* translators: %s: test code (test mode only, shown to administrators). */
						'testCode'     => __( 'Test mode (visible to administrators only): the code is %s.', 'labyrinthe-connect' ),
						'verified'     => __( 'Phone number verified.', 'labyrinthe-connect' ),
						/* translators: %d: number of characters of the code. */
						'codeLength'   => __( 'The code has %d characters.', 'labyrinthe-connect' ),
						'networkError' => __( 'Connection problem. Please try again.', 'labyrinthe-connect' ),
					),
				)
			);
		}
		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::SCRIPT );
	}
}
