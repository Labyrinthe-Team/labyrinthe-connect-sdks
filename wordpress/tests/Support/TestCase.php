<?php
/**
 * Base des tests du plugin.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Support;

use LabyrintheConnect\Client_Factory;
use LabyrintheConnect\Crypto;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Otp\Otp_Service;
use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Otp\Rate_Limiter;
use LabyrintheConnect\Otp\Session;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Sms_Sender;

/**
 * Réglages réinitialisés et API simulée à chaque test.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/** Clé de test fictive (format réel : lsk_test_xx_ + 40 caractères). */
	public const API_KEY = 'lsk_test_ab_0123456789abcdefghijklmnopqrstuvwxyzABCD';

	protected HttpMock $http;

	public function set_up(): void {
		parent::set_up();
		$this->http = new HttpMock();
		$this->http->install();
		delete_option( Settings::OPTION );
		$_COOKIE                = array();
		$_POST                  = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	public function tear_down(): void {
		$this->http->uninstall();
		$_COOKIE = array();
		$_POST   = array();
		parent::tear_down();
	}

	/**
	 * Enregistre des réglages (clé API de test incluse).
	 *
	 * @param array<string, mixed> $settings Réglages.
	 */
	protected function configure( array $settings = array() ): Settings {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array( 'api_key' => Crypto::encrypt( self::API_KEY ) ),
				$settings
			)
		);

		return new Settings();
	}

	protected function sender( Settings $settings ): Sms_Sender {
		return new Sms_Sender( $settings, new Client_Factory( $settings ), new Message_Log() );
	}

	protected function otp( Settings $settings, ?Session $session = null ): Otp_Service {
		return new Otp_Service( $settings, new Client_Factory( $settings ), $session ?? new Session(), new Rate_Limiter(), new Phone_Users( $settings ), new Message_Log() );
	}

	/**
	 * Message tel que renvoyé par l'API.
	 *
	 * @param array<string, mixed> $overrides Champs modifiés.
	 * @return array<string, mixed>
	 */
	protected static function api_message( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                  => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
				'reference'           => null,
				'recipient'           => '243812345678',
				'sender'              => 'LABYRINTHE',
				'encoding'            => 'gsm7',
				'status'              => 'queued',
				'segments'            => 1,
				'credits_charged'     => 1,
				'campaign_id'         => null,
				'provider_message_id' => null,
				'error_code'          => null,
				'error_message'       => null,
				'created_at'          => '2026-11-06T09:39:11.000000Z',
				'submitted_at'        => null,
				'delivered_at'        => null,
			),
			$overrides
		);
	}

	/**
	 * Lignes du journal, la plus récente d'abord.
	 *
	 * @return list<array<string, string>>
	 */
	protected function log_rows(): array {
		return ( new Message_Log() )->query( array( 'per_page' => 100 ) )['items'];
	}
}
