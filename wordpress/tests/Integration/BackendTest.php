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
	}

	public function test_otp_send_and_verify_with_the_test_code(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$otp   = new Otp_Service( $this->settings, new Client_Factory( $this->settings ), new Session(), new Rate_Limiter(), new Phone_Users( $this->settings ), new Message_Log() );
		$phone = '081' . wp_rand( 1000000, 9999999 );

		$sent = $otp->send( Otp_Service::CONTEXT_REGISTER, $phone );
		$this->assertTrue( $sent['ok'], (string) $sent['message'] );
		$this->assertArrayNotHasKey( 'otp_id', $sent );
		$this->assertArrayHasKey( 'test_code', $sent, 'Test mode + administrator: the test code is shown.' );

		$wrong = $otp->verify( Otp_Service::CONTEXT_REGISTER, '000000' === $sent['test_code'] ? '111111' : '000000' );
		$this->assertFalse( $wrong['ok'] );
		$this->assertSame( 'invalid_code', $wrong['code'] );

		$verified = $otp->verify( Otp_Service::CONTEXT_REGISTER, (string) $sent['test_code'] );
		$this->assertTrue( $verified['ok'], (string) $verified['message'] );
		$this->assertTrue( $otp->is_verified( Otp_Service::CONTEXT_REGISTER, (string) $verified['phone'] ) );

		$again = $otp->send( Otp_Service::CONTEXT_REGISTER, $phone );
		$this->assertFalse( $again['ok'] );
		$this->assertSame( 'OTP_RESEND_TOO_SOON', $again['code'] );
		$this->assertGreaterThan( 0, $again['retry_after'] );
	}
}
