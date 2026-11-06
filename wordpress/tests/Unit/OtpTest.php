<?php
/**
 * OTP : envoi, vérification, erreurs, limitation, connexion, inscription, commande.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Account_Status;
use LabyrintheConnect\Crypto;
use LabyrintheConnect\Otp\Checkout;
use LabyrintheConnect\Otp\Login;
use LabyrintheConnect\Otp\Otp_Service;
use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Otp\Registration;
use LabyrintheConnect\Otp\Session;
use LabyrintheConnect\Otp\Widget;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Tests\Support\TestCase;

final class OtpTest extends TestCase {

	private const OTP_ID = '7b2e4c10-3f5a-4d8e-a1b9-0c6d2e8f4a71';

	private Settings $settings;

	private Otp_Service $otp;

	public function set_up(): void {
		parent::set_up();
		$this->settings = $this->configure(
			array(
				'otp_login_enabled'    => true,
				'otp_register_enabled' => true,
				'otp_checkout_enabled' => true,
			)
		);
		$this->otp      = $this->otp( $this->settings );
	}

	/**
	 * Réponse de POST /otp/send.
	 *
	 * @param string|null           $test_code Code de test (clé lsk_test_).
	 * @param array<string, mixed>  $overrides Champs modifiés.
	 * @param array<string, string> $headers   En-têtes.
	 */
	private function push_sent( ?string $test_code = '482913', array $overrides = array(), array $headers = array() ): void {
		$data = array_merge(
			array(
				'otp_id'               => self::OTP_ID,
				'expires_at'           => gmdate( 'c', time() + 600 ),
				'max_attempts'         => 3,
				'code_length'          => 6,
				'code_type'            => 'numeric',
				'resend_after_seconds' => 60,
			),
			$overrides
		);
		if ( null !== $test_code ) {
			$data['test_code'] = $test_code;
		}
		$this->http->push( 201, array( 'data' => $data ), $headers );
	}

	private function push_verified( bool $verified, ?string $reason = null, ?int $attempts_remaining = null ): void {
		$this->http->push(
			200,
			array(
				'data' => array(
					'verified'           => $verified,
					'reason'             => $reason,
					'attempts_remaining' => $attempts_remaining,
				),
			)
		);
	}

	/**
	 * Idempotency-Key de chaque requête envoyée.
	 *
	 * @return list<string>
	 */
	private function idempotency_keys(): array {
		return array_values( array_map( static fn( array $request ): string => $request['headers']['idempotency-key'] ?? '', $this->http->requests ) );
	}

	public function test_send_keeps_the_otp_id_server_side(): void {
		$this->push_sent();

		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( '24381*****78', $result['message'] );
		$this->assertArrayNotHasKey( 'test_code', $result, 'Only administrators see the test code.' );
		$this->assertStringNotContainsString( self::OTP_ID, (string) wp_json_encode( $result ) );
		$this->assertSame( '+243812345678', $this->http->last()['body']['phone'] );
		$this->assertSame( 'en', $this->http->last()['body']['locale'], 'Language of the site (en_US in the tests).' );

		$cookie = $_COOKIE[ Session::COOKIE ];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $cookie );
		$stored = get_transient( 'labyrinthe_otp_' . hash( 'sha256', $cookie ) );
		$this->assertSame( self::OTP_ID, $stored['pending']['register']['otp_id'] );

		$row = $this->log_rows()[0];
		$this->assertSame( 'otp_register', $row['context'] );
		$this->assertSame( 'otp_sent', $row['status'] );
		$this->assertStringNotContainsString( '482913', (string) wp_json_encode( $this->log_rows() ), 'The code is never logged.' );
	}

	public function test_test_code_is_shown_to_administrators_in_test_mode_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->push_sent();
		$this->assertSame( '482913', $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['test_code'] );

		update_option( Settings::OPTION, array_merge( get_option( Settings::OPTION ), array( 'api_key' => Crypto::encrypt( 'lsk_live_ab_' . str_repeat( 'x', 40 ) ) ) ) );
		$this->push_sent();
		$this->assertArrayNotHasKey( 'test_code', $this->otp( new Settings() )->send( Otp_Service::CONTEXT_REGISTER, '0899999999' ) );
	}

	public function test_custom_template_is_sent(): void {
		$this->settings = $this->configure( array( 'otp_template' => 'MaBoutique : {code}' ) );
		$this->push_sent();

		$this->otp( $this->settings )->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->assertSame( 'MaBoutique : {code}', $this->http->last()['body']['template'] );
	}

	public function test_unresolved_idempotency_key_is_a_final_failure_and_the_next_request_uses_a_new_key(): void {
		$this->http->push_error( 409, 'IDEMPOTENCY_KEY_UNRESOLVED', array(), array( 'x-request-id' => 'req-unresolved' ) );

		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'SERVICE_UNAVAILABLE', $result['code'] );
		$this->assertCount( 1, $this->http->requests, 'Never retried automatically: a code may have been sent.' );
		$row = $this->log_rows()[0];
		$this->assertSame( 'IDEMPOTENCY_KEY_UNRESOLVED', $row['error_code'] );
		$this->assertSame( 'req-unresolved', $row['request_id'] );

		// Le visiteur redemande un code : nouvelle clé, sinon l'API répondrait encore 409.
		$this->push_sent();
		$this->assertTrue( $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['ok'] );
		$keys = $this->idempotency_keys();
		$this->assertCount( 2, $keys );
		$this->assertNotSame( $keys[0], $keys[1] );
	}

	public function test_resend_too_soon_returns_a_countdown(): void {
		$this->http->push_error( 429, 'OTP_RESEND_TOO_SOON', array( 'retry_after_seconds' => 42 ) );

		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'OTP_RESEND_TOO_SOON', $result['code'] );
		$this->assertSame( 42, $result['retry_after'] );
		$this->assertStringContainsString( '42', $result['message'] );
		$this->assertCount( 1, $this->http->requests, 'OTP sends are never retried automatically.' );
	}

	public function test_api_errors_are_mapped_to_friendly_messages(): void {
		$this->http->push_error(
			429,
			'OTP_RATE_LIMITED',
			array(
				'scope' => 'phone',
				'limit' => 5,
			)
		);
		$this->assertSame( 'OTP_RATE_LIMITED', $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['code'] );

		$this->http->push_error( 422, 'RECIPIENT_NOT_SENDABLE', array( 'reason' => 'blacklisted' ) );
		$this->assertSame( 'RECIPIENT_NOT_SENDABLE', $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['code'] );

		// 5xx et erreur réseau : une relance automatique, sans risque grâce à l'Idempotency-Key.
		$this->http->push_error( 503, 'HTTP_ERROR' )->push_error( 503, 'HTTP_ERROR' );
		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->assertSame( 'SERVICE_UNAVAILABLE', $result['code'] );

		$this->http->push_network_error()->push_network_error();
		$this->assertSame( 'SERVICE_UNAVAILABLE', $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['code'] );
		$this->assertCount( 6, $this->http->requests );
		$this->assertCount( 1, array_unique( $this->idempotency_keys() ), 'No send succeeded: every attempt replays the same key.' );
	}

	public function test_a_platform_maintenance_shows_the_announced_message_and_is_not_retried(): void {
		$until = time() + 2 * HOUR_IN_SECONDS;
		$this->http->push_error(
			503,
			'OTP_SERVICE_DISABLED',
			array(
				'feature'    => 'otp',
				'blocked_by' => 'otp',
				'message'    => '<b>Maintenance</b> du service OTP',
				'until'      => gmdate( 'c', $until ),
			),
			array(
				'retry-after'  => '7200',
				'x-request-id' => 'req-maint',
			)
		);

		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'FEATURE_DISABLED', $result['code'] );
		$this->assertStringStartsWith( 'Maintenance du service OTP', $result['message'], 'Tags of the administrator message are removed.' );
		$this->assertStringContainsString( 'Expected back: ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $until ), $result['message'] );
		$this->assertSame( 7200, $result['retry_after'] );
		$this->assertCount( 1, $this->http->requests, 'Retry-After above the SDK limit: not retried.' );
		$this->assertSame( 'OTP_SERVICE_DISABLED', $this->log_rows()[0]['error_code'] );
		$this->assertSame( 'req-maint', $this->log_rows()[0]['request_id'] );

		// Sans message ni retour programmé : message générique, pas de compte à rebours.
		$this->http->push_error(
			503,
			'FEATURE_DISABLED',
			array(
				'feature'    => 'api.otp_send',
				'blocked_by' => 'api',
				'message'    => null,
				'until'      => null,
			)
		);
		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->assertSame( 'FEATURE_DISABLED', $result['code'] );
		$this->assertSame( 'Sending codes by SMS is temporarily unavailable (maintenance). Please try again later.', $result['message'] );
		$this->assertArrayNotHasKey( 'retry_after', $result );
		$this->assertCount( 2, $this->http->requests, 'Never retried without Retry-After.' );
	}

	public function test_a_maintenance_during_verification_keeps_the_pending_code(): void {
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->http->push_error(
			503,
			'FEATURE_DISABLED',
			array(
				'feature'    => 'api.otp_verify',
				'blocked_by' => 'api.otp_verify',
				'message'    => null,
				'until'      => null,
			)
		);

		$result = $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '482913' );

		$this->assertSame( 'FEATURE_DISABLED', $result['code'] );
		$this->assertSame( 'Codes cannot be checked at the moment (maintenance). Please try again later.', $result['message'] );
		$this->assertCount( 2, $this->http->requests );

		// Le code en attente reste utilisable après la maintenance.
		$this->push_verified( true );
		$this->assertTrue( $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '482913' )['ok'] );
	}

	public function test_send_uses_web_otp_strip_accents_and_a_request_id(): void {
		$this->settings = $this->configure( array( 'strip_accents' => true ) );
		$this->push_sent();

		$result = $this->otp( $this->settings )->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$request = $this->http->last();
		$this->assertSame( 'example.org', $request['body']['web_otp_domain'], 'Domain of the site by default.' );
		$this->assertTrue( $request['body']['strip_accents'] );
		$this->assertMatchesRegularExpression( '/^wp-otp-[a-f0-9]{8}-[a-f0-9]{32}-1$/', $request['headers']['idempotency-key'] );
		$this->assertStringNotContainsString( '243812345678', $request['headers']['idempotency-key'], 'The key does not reveal the number.' );
		$this->assertStringStartsWith( 'wp-', $request['headers']['x-request-id'] );
		$this->assertSame( $request['headers']['x-request-id'], $this->log_rows()[0]['request_id'] );
		$this->assertSame( 6, $result['code_length'] );
		$this->assertSame( 'numeric', $result['code_type'] );
		$this->assertSame( 60, $result['resend_in'] );
	}

	public function test_web_otp_domain_setting(): void {
		$this->settings = $this->configure( array( 'otp_web_otp_domain' => 'shop.example.com' ) );
		$this->push_sent();
		$this->otp( $this->settings )->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->assertSame( 'shop.example.com', $this->http->last()['body']['web_otp_domain'] );

		$this->settings = $this->configure( array( 'otp_web_otp' => false ) );
		$this->push_sent();
		$this->otp( $this->settings )->send( Otp_Service::CONTEXT_REGISTER, '0812345679' );
		$this->assertArrayNotHasKey( 'web_otp_domain', $this->http->last()['body'], 'Turned off.' );
		$this->assertArrayNotHasKey( 'strip_accents', $this->http->last()['body'], 'Off by default.' );

		// Site local : aucun domaine valide, le Web OTP est ignoré (sinon 422).
		add_filter( 'labyrinthe_connect_web_otp_domain', static fn(): string => 'localhost' );
		$this->settings = $this->configure();
		$this->assertNull( $this->settings->web_otp_domain() );
		remove_all_filters( 'labyrinthe_connect_web_otp_domain' );
	}

	public function test_idempotency_key_is_stable_until_a_send_succeeds(): void {
		$this->settings = $this->configure( array( 'otp_ip_send_limit' => 20 ) );
		$this->otp      = $this->otp( $this->settings );
		// Première tentative perdue (réseau, deux fois) : rien n'est enregistré côté site.
		$this->http->push_network_error()->push_network_error();
		$this->assertFalse( $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['ok'] );

		// Nouveau clic : même clé (l'API rejouerait l'envoi s'il avait abouti), puis succès.
		$this->push_sent();
		$this->assertTrue( $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['ok'] );

		// « Renvoyer le code » après un envoi réussi : nouvelle clé, donc nouveau code.
		$this->push_sent();
		$this->assertTrue( $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['ok'] );

		// Autre numéro, autre contexte, autre navigateur : autres clés.
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0899999999' );
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_CHECKOUT, '0812345678' );
		$_COOKIE = array();
		$this->push_sent();
		$this->otp( $this->settings, new Session() )->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$keys = $this->idempotency_keys();
		$this->assertCount( 7, $keys );
		$this->assertSame( $keys[0], $keys[1], 'Same key for the retry of the SDK.' );
		$this->assertSame( $keys[0], $keys[2], 'Same key for a new click after a failure.' );
		$this->assertStringEndsWith( '-1', $keys[2] );
		$this->assertStringEndsWith( '-2', $keys[3] );
		$this->assertCount( 5, array_unique( array_slice( $keys, 2 ) ) );
	}

	public function test_replayed_send_keeps_the_same_code_and_is_logged_once(): void {
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->assertCount( 1, $this->log_rows() );

		// Double requête simultanée : l'API rejoue le premier envoi (même otp_id, aucun SMS).
		$this->push_sent( '482913', array(), array( 'idempotent-replayed' => 'true' ) );
		$this->assertTrue( $this->otp( $this->settings, new Session() )->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['ok'] );

		$this->assertCount( 1, $this->log_rows(), 'A replay sent no SMS: no second log row.' );
	}

	public function test_alphanumeric_codes_and_their_shape_are_remembered(): void {
		$this->push_sent(
			null,
			array(
				'code_length'          => 8,
				'code_type'            => 'alphanumeric',
				'max_attempts'         => 4,
				'resend_after_seconds' => 30,
			)
		);

		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->assertSame( 8, $result['code_length'] );
		$this->assertSame( 'alphanumeric', $result['code_type'] );
		$this->assertSame( 30, $result['resend_in'] );
		$this->assertSame(
			array(
				'code_length'  => 8,
				'code_type'    => 'alphanumeric',
				'max_attempts' => 4,
				'resend_after' => 30,
			),
			Otp_Service::shape()
		);

		// Le formulaire suit la forme : clavier texte, 8 caractères.
		$html = Widget::render( Otp_Service::CONTEXT_REGISTER );
		$this->assertStringContainsString( 'autocomplete="one-time-code"', $html );
		$this->assertStringContainsString( 'inputmode="text" maxlength="8"', $html );
		$this->assertStringContainsString( 'data-code-type="alphanumeric"', $html );
		$this->assertStringNotContainsString( 'pattern=', $html, 'No HTML validation that would block the enclosing form.' );
	}

	public function test_numeric_code_field_by_default(): void {
		$html = Widget::render( Otp_Service::CONTEXT_REGISTER );

		$this->assertStringContainsString( 'inputmode="numeric" maxlength="6"', $html );
		$this->assertStringContainsString( 'autocomplete="one-time-code"', $html );
	}

	public function test_account_suspended_is_logged_with_the_request_id(): void {
		$this->http->push_error( 403, 'ACCOUNT_SUSPENDED', array(), array( 'x-request-id' => 'req-abc-123' ) );

		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->assertSame( 'SERVICE_UNAVAILABLE', $result['code'], 'Visitors get a neutral message.' );
		$this->assertCount( 1, $this->http->requests, 'Never retried.' );
		$row = $this->log_rows()[0];
		$this->assertSame( 'ACCOUNT_SUSPENDED', $row['error_code'] );
		$this->assertSame( 'req-abc-123', $row['request_id'] );
		$this->assertSame( 'req-abc-123', Account_Status::suspended()['request_id'] ?? null );
	}

	public function test_invalid_phone_is_refused_without_any_request(): void {
		$result = $this->otp->send( Otp_Service::CONTEXT_REGISTER, '12' );

		$this->assertSame( 'INVALID_PHONE', $result['code'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_sends_are_limited_per_ip(): void {
		$this->settings = $this->configure( array( 'otp_ip_send_limit' => 2 ) );
		$otp            = $this->otp( $this->settings );
		$this->push_sent();
		$this->push_sent();

		$this->assertTrue( $otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' )['ok'] );
		$this->assertTrue( $otp->send( Otp_Service::CONTEXT_REGISTER, '0812345679' )['ok'] );
		$limited = $otp->send( Otp_Service::CONTEXT_REGISTER, '0812345670' );

		$this->assertSame( 'IP_RATE_LIMITED', $limited['code'] );
		$this->assertGreaterThan( 0, $limited['retry_after'] );
		$this->assertCount( 2, $this->http->requests );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->push_sent();
		$this->assertTrue( $otp->send( Otp_Service::CONTEXT_REGISTER, '0812345670' )['ok'], 'Another IP is not limited.' );
	}

	public function test_verify_success_marks_the_phone_as_verified(): void {
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->push_verified( true );

		$result = $this->otp->verify( Otp_Service::CONTEXT_REGISTER, ' 482 913 ' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame(
			array(
				'otp_id' => self::OTP_ID,
				'code'   => '482913',
			),
			$this->http->last()['body']
		);
		$this->assertTrue( $this->otp->is_verified( Otp_Service::CONTEXT_REGISTER, '243812345678' ) );
		$this->assertFalse( $this->otp->is_verified( Otp_Service::CONTEXT_CHECKOUT, '243812345678' ), 'A verification is bound to its context.' );

		$this->assertSame( 'NO_PENDING_CODE', $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '482913' )['code'], 'A code is used once.' );
	}

	public function test_wrong_code_keeps_the_pending_code_and_final_reasons_clear_it(): void {
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$this->push_verified( false, 'invalid_code', 2 );
		$wrong = $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '000000' );
		$this->assertSame( 'invalid_code', $wrong['code'] );
		$this->assertSame( 2, $wrong['attempts_remaining'] );
		$this->assertStringContainsString( '2 attempts left', $wrong['message'] );

		$this->push_verified( false, 'invalid_code', 1 );
		$this->assertStringContainsString( '1 attempt left', $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '000002' )['message'] );

		$this->push_verified( false, 'max_attempts_reached', 0 );
		$blocked = $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '000001' );
		$this->assertSame( 'max_attempts_reached', $blocked['code'] );
		$this->assertSame( 0, $blocked['attempts_remaining'] );

		$this->assertSame( 'NO_PENDING_CODE', $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '482913' )['code'] );
		$this->assertCount( 4, $this->http->requests );
	}

	public function test_expired_or_unknown_otp_is_reported_as_expired(): void {
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->http->push_error( 404, 'NOT_FOUND' );

		$this->assertSame( 'expired', $this->otp->verify( Otp_Service::CONTEXT_REGISTER, '482913' )['code'] );
	}

	public function test_another_browser_cannot_use_the_pending_code(): void {
		$this->push_sent();
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );

		$_COOKIE = array();
		$other   = $this->otp( $this->settings, new Session() );

		$this->assertSame( 'NO_PENDING_CODE', $other->verify( Otp_Service::CONTEXT_REGISTER, '482913' )['code'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_login_with_an_unknown_number_sends_nothing_but_looks_the_same(): void {
		$result = $this->otp->send( Otp_Service::CONTEXT_LOGIN, '0812345678' );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( '24381*****78', $result['message'] );
		$this->assertSame( array(), $this->http->requests, 'No SMS (no credit) for a number without account.' );

		$this->assertSame( 6, $result['code_length'], 'Same shape as a real code.' );
		$this->assertSame( 60, $result['resend_in'] );
		for ( $i = 0; $i < 4; $i++ ) {
			$wrong = $this->otp->verify( Otp_Service::CONTEXT_LOGIN, '123456' );
			$this->assertSame( 'invalid_code', $wrong['code'] );
			$this->assertSame( 4 - $i, $wrong['attempts_remaining'], 'Same countdown as a real code.' );
		}
		$this->assertSame( 'max_attempts_reached', $this->otp->verify( Otp_Service::CONTEXT_LOGIN, '123456' )['code'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_login_with_a_known_number_logs_the_user_in(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		Phone_Users::set( $user_id, '243812345678' );
		$this->push_sent();
		$this->push_verified( true );

		$this->assertTrue( $this->otp->send( Otp_Service::CONTEXT_LOGIN, '+243 81 234 5678' )['ok'] );
		$verified = $this->otp->verify( Otp_Service::CONTEXT_LOGIN, '482913' );
		$this->assertTrue( $verified['ok'] );
		$this->assertSame( $user_id, $verified['user_id'] );

		$login  = new Login( $this->settings, new Phone_Users( $this->settings ) );
		$result = $login->log_in( $user_id, false, 'https://evil.example.net/' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertStringStartsWith( home_url(), $result['redirect'], 'External redirects are refused.' );
	}

	public function test_administrators_cannot_log_in_by_sms_unless_allowed(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Phone_Users::set( $admin, '243812345678' );

		$this->otp->send( Otp_Service::CONTEXT_LOGIN, '0812345678' );
		$this->assertSame( array(), $this->http->requests, 'Treated like an unknown number.' );

		$settings = $this->configure(
			array(
				'otp_login_enabled' => true,
				'otp_allow_admins'  => true,
			)
		);
		$this->push_sent();
		$this->otp( $settings )->send( Otp_Service::CONTEXT_LOGIN, '0812345679' );
		$this->assertSame( array(), $this->http->requests, 'Other number: still no account.' );
		$this->otp( $settings )->send( Otp_Service::CONTEXT_LOGIN, '0812345678' );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_a_number_shared_by_two_accounts_cannot_log_in(): void {
		Phone_Users::set( self::factory()->user->create(), '243812345678' );
		Phone_Users::set( self::factory()->user->create(), '243812345678' );

		$this->otp->send( Otp_Service::CONTEXT_LOGIN, '0812345678' );

		$this->assertSame( array(), $this->http->requests );
	}

	public function test_registration_requires_a_verified_phone(): void {
		$registration = new Registration( $this->settings, $this->otp );

		$_POST[ Registration::FIELD ] = '0812345678';
		$errors                       = $registration->validate( new \WP_Error() );
		$this->assertContains( 'labyrinthe_phone_not_verified', $errors->get_error_codes() );

		$this->push_sent();
		$this->push_verified( true );
		$this->otp->send( Otp_Service::CONTEXT_REGISTER, '0812345678' );
		$this->otp->verify( Otp_Service::CONTEXT_REGISTER, '482913' );

		$this->assertSame( array(), $registration->validate( new \WP_Error() )->get_error_codes() );

		$user_id = self::factory()->user->create();
		$registration->save( $user_id );
		$this->assertSame( '243812345678', Phone_Users::get( $user_id ) );
		$this->assertSame( '+243812345678', get_user_meta( $user_id, 'billing_phone', true ) );
		$this->assertFalse( $this->otp->is_verified( Otp_Service::CONTEXT_REGISTER, '243812345678' ), 'The verification is used once.' );
	}

	public function test_registration_without_phone_is_refused(): void {
		$errors = ( new Registration( $this->settings, $this->otp ) )->validate( new \WP_Error() );

		$this->assertContains( 'labyrinthe_phone_missing', $errors->get_error_codes() );
	}

	public function test_checkout_requires_the_billing_phone_to_be_verified(): void {
		$checkout = new Checkout( $this->settings, $this->otp );
		$data     = array(
			'billing_phone'   => '081 234 5678',
			'billing_country' => 'CD',
		);

		$errors = new \WP_Error();
		$checkout->validate_classic( $data, $errors );
		$this->assertContains( 'labyrinthe_phone_not_verified', $errors->get_error_codes() );

		$this->push_sent();
		$this->push_verified( true );
		$this->otp->send( Otp_Service::CONTEXT_CHECKOUT, '0812345678' );
		$this->otp->verify( Otp_Service::CONTEXT_CHECKOUT, '482913' );

		$errors = new \WP_Error();
		$checkout->validate_classic( $data, $errors );
		$this->assertSame( array(), $errors->get_error_codes() );

		$errors = new \WP_Error();
		$checkout->validate_classic( array( 'billing_phone' => '0899999999' ), $errors );
		$this->assertContains( 'labyrinthe_phone_not_verified', $errors->get_error_codes(), 'Another number must be verified too.' );
	}

	public function test_checkout_accepts_the_verified_phone_of_the_logged_in_customer(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		Phone_Users::set( $user_id, '243812345678' );
		wp_set_current_user( $user_id );

		$errors = new \WP_Error();
		( new Checkout( $this->settings, $this->otp ) )->validate_classic( array( 'billing_phone' => '+243812345678' ), $errors );

		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_block_checkout_throws_a_route_exception(): void {
		$order = wc_create_order();
		$order->set_billing_phone( '0812345678' );

		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		( new Checkout( $this->settings, $this->otp ) )->validate_blocks( $order );
	}

	public function test_enabled_contexts_follow_the_settings(): void {
		$this->assertSame( array( 'login', 'register', 'checkout' ), $this->otp->enabled_contexts() );
		$this->assertSame( array(), $this->otp( $this->configure() )->enabled_contexts() );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'otp_login_enabled' => true ) ) );
		$this->assertSame( array(), $this->otp( new Settings() )->enabled_contexts(), 'Nothing without an API key.' );
	}
}
