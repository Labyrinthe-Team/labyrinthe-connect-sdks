<?php
/**
 * Test d'intégration contre un vrai backend (facultatif).
 *
 * LABYRINTHE_API_KEY=lsk_test_… [LABYRINTHE_BASE_URL=http://localhost:8000] composer test:integration
 *
 * Ignoré sans clé ; refuse une clé live (le test envoie des SMS).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Integration;

use LabyrintheConnect\Client_Factory;
use LabyrintheConnect\Crypto;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Otp\Otp_Service;
use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Otp\Rate_Limiter;
use LabyrintheConnect\Otp\Session;
use LabyrintheConnect\Phone;
use LabyrintheConnect\Sender_Ids;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Sms_Sender;

/**
 * @group integration
 */
final class BackendTest extends \WP_UnitTestCase {

	private Settings $settings;

	private string $base_url = '';

	public function set_up(): void {
		parent::set_up();

		$key = (string) getenv( 'LABYRINTHE_API_KEY' );
		if ( '' === $key ) {
			$this->markTestSkipped( 'LABYRINTHE_API_KEY is not set.' );
		}
		if ( 0 !== strpos( $key, 'lsk_test_' ) ) {
			$this->markTestSkipped( 'The integration test only runs with a lsk_test_ key.' );
		}

		$base_url       = (string) getenv( 'LABYRINTHE_BASE_URL' );
		$this->base_url = '' !== $base_url ? $base_url : 'http://localhost:8000';
		add_filter( 'labyrinthe_connect_base_url', array( $this, 'base_url' ) );

		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'api_key' => Crypto::encrypt( $key ) ) ) );
		$this->settings         = new Settings();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
	}

	public function tear_down(): void {
		remove_filter( 'labyrinthe_connect_base_url', array( $this, 'base_url' ) );
		parent::tear_down();
	}

	public function base_url(): string {
		return $this->base_url;
	}

	public function test_balance_and_sender_ids(): void {
		$balance = ( new Client_Factory( $this->settings ) )->make()->balance->get();
		$this->assertGreaterThanOrEqual( 0, $balance->held );

		$senders = ( new Sender_Ids( new Client_Factory( $this->settings ) ) )->refresh();
		$this->assertIsArray( $senders );
	}

	public function test_send_sms_is_logged_and_idempotent(): void {
		$sender = new Sms_Sender( $this->settings, new Client_Factory( $this->settings ), new Message_Log() );
		$key    = 'wp-integration-' . wp_generate_uuid4();
		$meta   = array(
			'context'         => 'test',
			'idempotency_key' => $key,
			'reference'       => 'wp-integration',
		);

		$first = $sender->send( '0812345678', 'Test Labyrinthe Connect WordPress', $meta );
		$this->assertTrue( $first->success, $first->error_code . ' ' . $first->error_message );
		$this->assertNotSame( '', $first->message_id );
		$this->assertTrue( $first->test_mode );

		$again = $sender->send( '0812345678', 'Test Labyrinthe Connect WordPress', $meta );
		$this->assertTrue( $again->success );
		$this->assertSame( $first->message_id, $again->message_id, 'Same Idempotency-Key: the API replays the first send.' );

		$row = ( new Message_Log() )->find_by_message_id( $first->message_id );
		$this->assertNotNull( $row );
		$this->assertSame( '24381*****78', $row['recipient'] );
		$this->assertStringStartsWith( 'wp-', $row['request_id'] );
	}

	public function test_accents_are_removed_by_the_api(): void {
		update_option( Settings::OPTION, array_merge( get_option( Settings::OPTION ), array( 'strip_accents' => true ) ) );
		$settings = new Settings();
		$sender   = new Sms_Sender( $settings, new Client_Factory( $settings ), new Message_Log() );

		$result = $sender->send( '0812345678', 'Votre commande est expédiée : « Merci » !', array( 'context' => 'test' ) );
		$this->assertTrue( $result->success, $result->error_code . ' ' . $result->error_message );
		$this->assertSame( 'gsm7', $result->encoding, 'strip_accents: the SMS stays in GSM-7.' );

		$client = ( new Client_Factory( $settings ) )->make();
		$this->assertSame( 'ucs2', $client->sms->estimate( 'Café élève', '+243812345678' )->encoding->value );
		$this->assertSame( 'gsm7', $client->sms->estimate( 'Café élève', '+243812345678', stripAccents: true )->encoding->value );
	}

	public function test_foreign_number_is_kept(): void {
		$sender = new Sms_Sender( $this->settings, new Client_Factory( $this->settings ), new Message_Log() );

		$result = $sender->send( '+33 6 12 34 56 78', 'Test international', array( 'context' => 'test' ) );

		$this->assertTrue( $result->success, $result->error_code . ' ' . $result->error_message );
		$this->assertSame( Phone::mask( '33612345678' ), $result->recipient, 'Not prefixed with 243.' );
	}

	public function test_short_foreign_number_keeps_its_country_code(): void {
		$client = ( new Client_Factory( $this->settings ) )->make();

		$this->assertSame( 2, $client->sms->estimate( 'Seychelles', array( '+2481234567', '0812345678' ) )->recipientsCount );

		// BACKEND-TODO n° 34 (corrigé) : POST /sms ne normalise plus deux fois le destinataire.
		$message = $client->sms->send( '+2481234567', 'Seychelles test' );
		$this->assertSame( '2481234567', $message->recipient );
	}

	public function test_api_errors_carry_a_request_id(): void {
		update_option( Settings::OPTION, array_merge( get_option( Settings::OPTION ), array( 'sender' => 'NOPE' . wp_rand( 1000, 9999 ) ) ) );
		$settings = new Settings();
		$sender   = new Sms_Sender( $settings, new Client_Factory( $settings ), new Message_Log() );

		$result = $sender->send( '0812345678', 'Test Sender ID', array( 'context' => 'test' ) );

		$this->assertFalse( $result->success );
		$this->assertSame( 'SENDER_ID_NOT_APPROVED', $result->error_code );
		$this->assertStringStartsWith( 'wp-', $result->request_id, 'The backend reuses the X-Request-Id of the plugin.' );
		$this->assertSame( $result->request_id, ( new Message_Log() )->find( $result->log_id )['request_id'] );
	}

	public function test_otp_send_and_verify_with_the_test_code(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$session = new Session();
		$otp     = new Otp_Service( $this->settings, new Client_Factory( $this->settings ), $session, new Rate_Limiter(), new Phone_Users( $this->settings ), new Message_Log() );
		$phone   = '081' . wp_rand( 1000000, 9999999 );

		$sent = $otp->send( Otp_Service::CONTEXT_REGISTER, $phone );
		$this->assertTrue( $sent['ok'], (string) $sent['message'] );
		$this->assertArrayNotHasKey( 'otp_id', $sent );
		$this->assertArrayHasKey( 'test_code', $sent, 'Test mode + administrator: the test code is shown.' );
		$this->assertGreaterThanOrEqual( 4, $sent['code_length'] );
		$this->assertSame( strlen( (string) $sent['test_code'] ), $sent['code_length'] );
		$this->assertContains( $sent['code_type'], array( 'numeric', 'alphanumeric' ) );
		$this->assertGreaterThan( 0, $sent['resend_in'] );

		// Réponse perdue (le site n'a rien enregistré) puis nouveau clic : même Idempotency-Key, l'API
		// rejoue l'envoi (même code, aucun SMS) au lieu de refuser un renvoi trop rapide.
		$key               = 'labyrinthe_otp_' . hash( 'sha256', (string) $_COOKIE[ Session::COOKIE ] );
		$recorded          = get_transient( $key );
		$recorded['sends'] = array();
		set_transient( $key, $recorded, HOUR_IN_SECONDS );
		$replayed = $otp->send( Otp_Service::CONTEXT_REGISTER, $phone );
		$this->assertTrue( $replayed['ok'], (string) $replayed['message'] );
		$this->assertSame( $sent['test_code'], $replayed['test_code'], 'Replay: the same code.' );

		$wrong = $otp->verify( Otp_Service::CONTEXT_REGISTER, str_repeat( '0', $sent['code_length'] ) === $sent['test_code'] ? str_repeat( '1', $sent['code_length'] ) : str_repeat( '0', $sent['code_length'] ) );
		$this->assertFalse( $wrong['ok'] );
		$this->assertSame( 'invalid_code', $wrong['code'] );
		$this->assertIsInt( $wrong['attempts_remaining'] );
		$this->assertStringContainsString( (string) $wrong['attempts_remaining'], (string) $wrong['message'] );

		$verified = $otp->verify( Otp_Service::CONTEXT_REGISTER, (string) $sent['test_code'] );
		$this->assertTrue( $verified['ok'], (string) $verified['message'] );
		$this->assertTrue( $otp->is_verified( Otp_Service::CONTEXT_REGISTER, (string) $verified['phone'] ) );

		// API 1.2.0 : renvoi accepté tout de suite après une vérification réussie (nouvelle clé : nouveau code).
		$next = $otp->send( Otp_Service::CONTEXT_REGISTER, $phone );
		$this->assertTrue( $next['ok'], (string) $next['message'] );

		// Ce code-là n'est pas vérifié : un nouvel envoi est trop rapproché.
		$again = $otp->send( Otp_Service::CONTEXT_REGISTER, $phone );
		$this->assertFalse( $again['ok'] );
		$this->assertSame( 'OTP_RESEND_TOO_SOON', $again['code'] );
		$this->assertGreaterThan( 0, $again['retry_after'] );
	}

	public function test_invalid_web_otp_domain_is_never_sent(): void {
		add_filter( 'labyrinthe_connect_web_otp_domain', static fn(): string => 'localhost' );
		$this->assertNull( $this->settings->web_otp_domain(), 'Would be a 422 VALIDATION_ERROR.' );
		remove_all_filters( 'labyrinthe_connect_web_otp_domain' );
		$this->assertSame( 'example.org', $this->settings->web_otp_domain() );
	}
}
