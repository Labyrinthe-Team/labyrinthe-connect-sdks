<?php
/**
 * Endpoint REST des webhooks : signature, dédoublonnage, mise à jour du journal, actions.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Crypto;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Tests\Support\TestCase;
use LabyrintheConnect\Webhook\Controller;

final class WebhookTest extends TestCase {

	private const SECRET = 'whsec_test_0123456789abcdef';

	private const MESSAGE_ID = '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10';

	public function set_up(): void {
		parent::set_up();
		$this->configure( array( 'webhook_secret' => Crypto::encrypt( self::SECRET ) ) );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Enveloppe d'un événement.
	 *
	 * @param array<string, mixed> $data Données.
	 */
	private static function envelope( string $event, array $data, ?string $id = null, ?int $timestamp = null ): string {
		return (string) wp_json_encode(
			array(
				'id'        => $id ?? wp_generate_uuid4(),
				'event'     => $event,
				'data'      => $data,
				'timestamp' => $timestamp ?? time(),
			)
		);
	}

	/**
	 * Événement message.status_changed.
	 *
	 * @param array<string, mixed> $extra Champs ajoutés (reference, error_code, error_message : spec 1.2.0).
	 */
	private static function status_event( string $status, ?string $id = null, array $extra = array() ): string {
		return self::envelope(
			'message.status_changed',
			array_merge(
				array(
					'message_id'          => self::MESSAGE_ID,
					'recipient'           => '243812345678',
					'status'              => $status,
					'provider_message_id' => 'DD-1',
					'campaign_id'         => null,
				),
				$extra
			),
			$id
		);
	}

	private function deliver( string $body, ?string $signature = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/' . Controller::NAMESPACE . Controller::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Signature', $signature ?? 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET ) );
		$request->set_body( $body );

		return rest_get_server()->dispatch( $request );
	}

	private function log_message( int $order_id = 0 ): int {
		return ( new Message_Log() )->insert(
			array(
				'message_id' => self::MESSAGE_ID,
				'recipient'  => '24381*****78',
				'context'    => 'order_status',
				'order_id'   => $order_id,
				'status'     => 'queued',
			)
		);
	}

	public function test_route_is_public_and_only_accepts_post(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/labyrinthe/v1/webhook', $routes );
		$this->assertSame( array( 'POST' => true ), $routes['/labyrinthe/v1/webhook'][0]['methods'] );
		$this->assertStringEndsWith( '/labyrinthe/v1/webhook', Controller::url() );
	}

	public function test_valid_status_event_updates_the_log_and_fires_actions(): void {
		$id       = $this->log_message();
		$received = array();
		add_action(
			'labyrinthe_message_status_changed',
			static function ( $envelope, $row ) use ( &$received ): void {
				$received[] = array( $envelope, $row );
			},
			10,
			2
		);

		$response = $this->deliver( self::status_event( 'delivered' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'received' => true ), $response->get_data() );
		$this->assertSame( 'delivered', ( new Message_Log() )->find( $id )['status'] );
		$this->assertCount( 1, $received );
		$this->assertSame( 'message.status_changed', $received[0][0]['event'] );
		$this->assertSame( self::MESSAGE_ID, $received[0][0]['data']['message_id'] );
		$this->assertSame( 'delivered', $received[0][1]['status'] );
		$this->assertSame( 1, did_action( 'labyrinthe_webhook_received' ) );
	}

	public function test_invalid_signature_is_rejected_without_side_effects(): void {
		$id   = $this->log_message();
		$body = self::status_event( 'delivered' );

		$this->assertSame( 400, $this->deliver( $body, 'sha256=' . hash_hmac( 'sha256', $body, 'wrong-secret-0123456' ) )->get_status() );
		$this->assertSame( 400, $this->deliver( $body, '' )->get_status() );
		$this->assertSame( 400, $this->deliver( $body . ' ', 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET ) )->get_status(), 'The signature covers the raw body.' );

		$this->assertSame( 'queued', ( new Message_Log() )->find( $id )['status'] );
		$this->assertSame( 0, did_action( 'labyrinthe_webhook_received' ) );
	}

	public function test_old_timestamp_is_rejected_as_a_replay(): void {
		$body = self::envelope( 'test.ping', array(), null, time() - 3600 );

		$this->assertSame( 400, $this->deliver( $body )->get_status() );
	}

	public function test_signed_invalid_payload_is_rejected(): void {
		$this->assertSame( 400, $this->deliver( '{"not":"an envelope"}' )->get_status() );
	}

	public function test_duplicate_delivery_is_acknowledged_once(): void {
		$this->log_message();
		$body = self::status_event( 'delivered', '1f0c7e52-8d3a-4b6e-9c2f-5a7d1e3b9f40' );

		$first  = $this->deliver( $body );
		$second = $this->deliver( $body );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame(
			array(
				'received'  => true,
				'duplicate' => true,
			),
			$second->get_data()
		);
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 1, did_action( 'labyrinthe_message_status_changed' ) );
	}

	public function test_failed_processing_releases_the_event_for_the_next_attempt(): void {
		$body  = self::envelope( 'test.ping', array(), 'c4d8e2a0-6b1f-4a3c-9e7d-2f5b8a1c6e93' );
		$throw = static function (): void {
			throw new \RuntimeException( 'boom' );
		};
		add_action( 'labyrinthe_webhook_test_ping', $throw );
		$this->assertSame( 500, $this->deliver( $body )->get_status() );
		remove_action( 'labyrinthe_webhook_test_ping', $throw );

		$response = $this->deliver( $body );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'duplicate', $response->get_data() );
		$this->assertGreaterThan( 0, (int) get_option( Controller::LAST_PING_OPTION ) );
	}

	public function test_final_status_is_never_downgraded(): void {
		$id = $this->log_message();

		$this->deliver( self::status_event( 'delivered' ) );
		$this->deliver( self::status_event( 'sent' ) );

		$this->assertSame( 'delivered', ( new Message_Log() )->find( $id )['status'] );
	}

	public function test_unknown_message_still_fires_the_action(): void {
		$rows = array();
		add_action(
			'labyrinthe_message_status_changed',
			static function ( $envelope, $row ) use ( &$rows ): void {
				$rows[] = $row;
			},
			10,
			2
		);

		$this->assertSame( 200, $this->deliver( self::status_event( 'delivered' ) )->get_status() );
		$this->assertSame( array( null ), $rows );
	}

	public function test_delivery_adds_an_order_note(): void {
		$order = wc_create_order();
		$this->log_message( $order->get_id() );

		$this->deliver( self::status_event( 'undeliverable' ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'undeliverable', $notes[0]->content );
		$this->assertStringContainsString( '24381*****78', $notes[0]->content );
	}

	public function test_failure_details_are_saved_in_the_log_and_the_order_note(): void {
		$order = wc_create_order();
		$id    = $this->log_message( $order->get_id() );

		$this->deliver(
			self::status_event(
				'failed',
				null,
				array(
					'reference'     => 'order-' . $order->get_id(),
					'error_code'    => 'IP_NOT_WHITELISTED',
					'error_message' => 'IP not Whitelisted',
				)
			)
		);

		$row = ( new Message_Log() )->find( $id );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'IP_NOT_WHITELISTED', $row['error_code'] );
		$this->assertSame( 'IP not Whitelisted', $row['error_message'] );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'IP_NOT_WHITELISTED', $notes[0]->content );
		$this->assertStringContainsString( 'IP not Whitelisted', $notes[0]->content );
	}

	public function test_reference_links_the_report_to_the_order_when_the_log_row_is_gone(): void {
		$order = wc_create_order();

		// Journal purgé (ou message envoyé par un autre canal avec la même référence) : la référence suffit.
		$this->deliver( self::status_event( 'delivered', null, array( 'reference' => 'order-' . $order->get_id() ) ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'delivered', $notes[0]->content );
		$this->assertStringContainsString( '24381*****78', $notes[0]->content, 'Masked number, never the full one.' );
		$this->assertStringNotContainsString( '243812345678', $notes[0]->content );
	}

	public function test_foreign_reference_or_intermediate_status_adds_no_note(): void {
		$order = wc_create_order();

		$this->deliver( self::status_event( 'delivered', null, array( 'reference' => 'invoice-' . $order->get_id() ) ) );
		$this->deliver( self::status_event( 'sent', null, array( 'reference' => 'order-' . $order->get_id() ) ) );

		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	public function test_otp_events_fire_their_actions(): void {
		$verified = array();
		$failed   = array();
		add_action(
			'labyrinthe_otp_verified',
			static function ( $envelope ) use ( &$verified ): void {
				$verified[] = $envelope;
			}
		);
		add_action(
			'labyrinthe_otp_failed',
			static function ( $envelope ) use ( &$failed ): void {
				$failed[] = $envelope;
			}
		);

		$ok = $this->deliver(
			self::envelope(
				'otp.verified',
				array(
					'otp_id'      => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
					'phone'       => '243812345678',
					'status'      => 'verified',
					'reason'      => null,
					'verified_at' => '2026-11-06T09:41:02+00:00',
				)
			)
		);
		$this->deliver(
			self::envelope(
				'otp.failed',
				array(
					'otp_id'      => '8e1b3d6f-0a2c-4e5b-9d7f-3c6a8e0b2d14',
					'phone'       => '243812345678',
					'status'      => 'failed',
					'reason'      => 'max_attempts_reached',
					'verified_at' => null,
				)
			)
		);

		$this->assertSame( 200, $ok->get_status() );
		$this->assertCount( 1, $verified );
		$this->assertSame( 'verified', $verified[0]['data']['status'] );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'max_attempts_reached', $failed[0]['data']['reason'] );
		$this->assertSame( 0, did_action( 'labyrinthe_message_status_changed' ) );
	}

	public function test_balance_low_and_campaign_completed(): void {
		$this->deliver(
			self::envelope(
				'balance.low',
				array(
					'balance'   => 950,
					'threshold' => 1000,
				)
			)
		);
		$this->deliver(
			self::envelope(
				'campaign.completed',
				array(
					'campaign_id'        => '3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20',
					'name'               => 'Promo',
					'recipients_count'   => 2,
					'messages_by_status' => array( 'delivered' => 2 ),
					'credits_charged'    => 2,
					'completed_at'       => null,
				)
			)
		);

		$low = get_option( Controller::BALANCE_LOW_OPTION );
		$this->assertSame( 950, $low['balance'] );
		$this->assertSame( 1000, $low['threshold'] );
		$this->assertSame( 1, did_action( 'labyrinthe_balance_low' ) );
		$this->assertSame( 1, did_action( 'labyrinthe_campaign_completed' ) );
	}

	public function test_missing_secret_answers_503_so_that_the_backend_retries(): void {
		$this->configure();

		$this->assertSame( 503, $this->deliver( self::status_event( 'delivered' ) )->get_status() );
	}
}
