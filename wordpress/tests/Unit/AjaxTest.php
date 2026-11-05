<?php
/**
 * Actions AJAX : OTP (public) et outils d'administration.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Admin\Admin;
use LabyrintheConnect\Client_Factory;
use LabyrintheConnect\Crypto;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Otp\Ajax;
use LabyrintheConnect\Otp\Login;
use LabyrintheConnect\Otp\Otp_Service;
use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Otp\Rate_Limiter;
use LabyrintheConnect\Otp\Session;
use LabyrintheConnect\Sender_Ids;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Sms_Sender;
use LabyrintheConnect\Tests\Support\HttpMock;
use LabyrintheConnect\Tests\Support\TestCase;

final class AjaxTest extends \WP_Ajax_UnitTestCase {

	private HttpMock $http;

	public function set_up(): void {
		parent::set_up();
		$this->http = new HttpMock();
		$this->http->install();
		$_COOKIE                = array();
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 1, 250 );

		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'api_key'           => Crypto::encrypt( TestCase::API_KEY ),
					'otp_login_enabled' => true,
				)
			)
		);

		// Instances neuves (la session OTP du plugin chargé garderait son jeton d'un test à l'autre).
		$settings = new Settings();
		$clients  = new Client_Factory( $settings );
		$service  = new Otp_Service( $settings, $clients, new Session(), new Rate_Limiter(), new Phone_Users( $settings ), new Message_Log() );
		foreach ( array( 'wp_ajax_labyrinthe_otp_send', 'wp_ajax_labyrinthe_otp_verify', 'wp_ajax_nopriv_labyrinthe_otp_send', 'wp_ajax_nopriv_labyrinthe_otp_verify' ) as $hook ) {
			remove_all_actions( $hook );
		}
		// admin_init de WooCommerce (cookies de suivi, assistant…) : sans rapport avec ces tests.
		remove_all_actions( 'admin_init' );
		( new Ajax( $service, new Login( $settings, new Phone_Users( $settings ) ) ) )->register();
		( new Admin( $settings, $clients, new Sms_Sender( $settings, $clients, new Message_Log() ), new Sender_Ids( $clients ) ) )->register();
	}

	public function tear_down(): void {
		$this->http->uninstall();
		$_COOKIE = array();
		parent::tear_down();
	}

	/**
	 * Lance une action AJAX et renvoie la réponse JSON décodée.
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $post   Données envoyées.
	 * @return array<string, mixed>
	 */
	private function ajax( string $action, array $post ): array {
		$_POST                = $post;
		$this->_last_response = '';
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$response = json_decode( $this->_last_response, true );

		return is_array( $response ) ? $response : array();
	}

	public function test_otp_send_requires_a_valid_nonce(): void {
		$response = $this->ajax(
			'labyrinthe_otp_send',
			array(
				'nonce'   => 'bad',
				'context' => 'login',
				'phone'   => '0812345678',
			)
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'invalid_nonce', $response['data']['code'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_disabled_context_is_refused(): void {
		$response = $this->ajax(
			'labyrinthe_otp_send',
			array(
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'context' => 'register',
				'phone'   => '0812345678',
			)
		);

		$this->assertSame( 'disabled', $response['data']['code'] );
	}

	public function test_otp_login_flow_never_exposes_the_otp_id(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		Phone_Users::set( $user_id, '243812345678' );
		$this->http->push(
			201,
			array(
				'data' => array(
					'otp_id'       => 'secret-otp-id',
					'expires_at'   => gmdate( 'c', time() + 600 ),
					'max_attempts' => 3,
					'test_code'    => '482913',
				),
			)
		);
		$this->http->push(
			200,
			array(
				'data' => array(
					'verified' => true,
					'reason'   => null,
				),
			)
		);

		$sent = $this->ajax(
			'labyrinthe_otp_send',
			array(
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'context' => 'login',
				'phone'   => '0812345678',
			)
		);
		$this->assertTrue( $sent['success'] );
		$this->assertStringNotContainsString( 'secret-otp-id', $this->_last_response );
		$this->assertStringNotContainsString( '482913', $this->_last_response, 'Visitors never see the test code.' );

		$verified = $this->ajax(
			'labyrinthe_otp_verify',
			array(
				'nonce'    => wp_create_nonce( Ajax::NONCE ),
				'context'  => 'login',
				'code'     => '482913',
				'redirect' => home_url( '/my-account/' ),
			)
		);
		$this->assertTrue( $verified['success'] );
		$this->assertSame( home_url( '/my-account/' ), $verified['data']['redirect'] );
		$this->assertArrayNotHasKey( 'user_id', $verified['data'] );
		$this->assertArrayNotHasKey( 'phone', $verified['data'] );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertSame( 'secret-otp-id', $this->http->last()['body']['otp_id'] );
	}

	public function test_admin_tools_require_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->ajax( 'labyrinthe_connect_test_connection', array( 'nonce' => wp_create_nonce( Admin::NONCE ) ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_admin_tools_require_the_admin_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->ajax( 'labyrinthe_connect_test_connection', array( 'nonce' => wp_create_nonce( Ajax::NONCE ) ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_connection_test_returns_the_balance_and_never_the_key(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->http->push(
			200,
			array(
				'data' => array(
					'balance' => 1200,
					'held'    => 30,
				),
			)
		);

		$response = $this->ajax( 'labyrinthe_connect_test_connection', array( 'nonce' => wp_create_nonce( Admin::NONCE ) ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 1200, $response['data']['balance'] );
		$this->assertTrue( $response['data']['test_mode'] );
		$this->assertStringEndsWith( '/api/public/v1/balance', $this->http->last()['url'] );
		$this->assertStringNotContainsString( TestCase::API_KEY, $this->_last_response );
	}

	public function test_refused_key_is_reported(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->http->push_error( 401, 'UNAUTHENTICATED' );

		$response = $this->ajax( 'labyrinthe_connect_test_connection', array( 'nonce' => wp_create_nonce( Admin::NONCE ) ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'UNAUTHENTICATED', $response['data']['code'] );
	}

	public function test_send_test_sms(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->http->push(
			202,
			array(
				'data' => array(
					array(
						'id'              => 'test-message-id',
						'recipient'       => '243812345678',
						'status'          => 'queued',
						'segments'        => 1,
						'credits_charged' => 1,
						'encoding'        => 'gsm7',
					),
				),
			)
		);

		$response = $this->ajax(
			'labyrinthe_connect_send_test_sms',
			array(
				'nonce'   => wp_create_nonce( Admin::NONCE ),
				'phone'   => '0812345678',
				'message' => 'Test {shop_name}',
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( '24381*****78', $response['data']['message'] );
		$this->assertSame( 'Test ' . get_bloginfo( 'name' ), $this->http->last()['body']['body'] );
		$this->assertArrayHasKey( 'idempotency-key', $this->http->last()['headers'] );
	}

	public function test_refresh_senders_caches_the_list(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->http->push(
			200,
			array(
				'data' => array(
					array(
						'id'         => '1',
						'value'      => 'MABOUTIQUE',
						'status'     => 'approved',
						'is_default' => true,
					),
				),
			)
		);

		$response = $this->ajax( 'labyrinthe_connect_refresh_senders', array( 'nonce' => wp_create_nonce( Admin::NONCE ) ) );

		$this->assertSame(
			array(
				array(
					'value'      => 'MABOUTIQUE',
					'is_default' => true,
				),
			),
			$response['data']['senders']
		);
		$this->assertSame( $response['data']['senders'], get_transient( Sender_Ids::CACHE_KEY ) );
	}
}
