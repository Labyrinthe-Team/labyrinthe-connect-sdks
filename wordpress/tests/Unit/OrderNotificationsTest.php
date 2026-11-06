<?php
/**
 * SMS de commande WooCommerce : planification, envoi, idempotence, notes.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Settings;
use LabyrintheConnect\Tests\Support\TestCase;
use LabyrintheConnect\WooCommerce\Order_Notifications;

final class OrderNotificationsTest extends TestCase {

	private Order_Notifications $notifications;

	public function set_up(): void {
		parent::set_up();
		$this->use_settings();
	}

	/**
	 * Réglages et service sous test.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 */
	private function use_settings( array $settings = array() ): Settings {
		$settings            = $this->configure( $settings );
		$this->notifications = new Order_Notifications( $settings, $this->sender( $settings ) );

		return $settings;
	}

	private function create_order( string $phone = '0812345678' ): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Marie' );
		$order->set_billing_last_name( 'Kabongo' );
		$order->set_billing_phone( $phone );
		$order->set_billing_country( 'CD' );
		$order->set_currency( 'USD' );
		$order->set_total( '25' );
		$order->save();

		return $order;
	}

	/**
	 * Notes de la commande (textes).
	 *
	 * @return list<string>
	 */
	private function notes( \WC_Order $order ): array {
		return array_values( array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
	}

	/**
	 * Actions planifiées en attente.
	 *
	 * @return array<int, mixed>
	 */
	private function pending_actions( string $hook ): array {
		return as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => Order_Notifications::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 50,
			)
		);
	}

	private function accepted(): void {
		$this->http->push( 202, array( 'data' => array( self::api_message() ) ) );
	}

	public function test_status_change_schedules_one_async_action_and_calls_no_api(): void {
		$order = $this->create_order();
		$order->update_status( 'processing' );
		// Le hook se déclenche une seconde fois : toujours une seule action.
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );

		$this->assertTrue( as_has_scheduled_action( Order_Notifications::HOOK_CUSTOMER, array( $order->get_id(), 'processing', 1 ), Order_Notifications::GROUP ) );
		$this->assertCount( 1, $this->pending_actions( Order_Notifications::HOOK_CUSTOMER ) );
		$this->assertSame( array(), $this->http->requests, 'No API call while the status changes (checkout is never blocked).' );
	}

	public function test_disabled_status_or_missing_key_schedules_nothing(): void {
		$order = $this->create_order();
		$order->update_status( 'cancelled' );
		$this->assertCount( 0, $this->pending_actions( Order_Notifications::HOOK_CUSTOMER ) );

		update_option( Settings::OPTION, Settings::defaults() );
		$order->update_status( 'processing' );
		$this->assertCount( 0, $this->pending_actions( Order_Notifications::HOOK_CUSTOMER ) );
	}

	public function test_scheduled_action_sends_the_sms_with_an_idempotency_key_and_adds_a_note(): void {
		$order = $this->create_order();
		$this->accepted();

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$request = $this->http->last();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringEndsWith( '/api/public/v1/sms', $request['url'] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $request['headers']['authorization'] );
		$this->assertSame( Order_Notifications::idempotency_key( $order->get_id(), 'processing' ), $request['headers']['idempotency-key'] );
		$this->assertMatchesRegularExpression( '/^wc-[0-9a-f]{8}-' . $order->get_id() . '-processing$/', $request['headers']['idempotency-key'] );
		$this->assertStringStartsWith( 'labyrinthe-php/', $request['headers']['user-agent'] );
		$this->assertStringContainsString( 'labyrinthe-wordpress/' . LABYRINTHE_CONNECT_VERSION, $request['headers']['user-agent'] );
		$this->assertNotNull( $request['body'] );
		$this->assertSame( '+243812345678', $request['body']['recipient'] );
		$this->assertSame( 'order-' . $order->get_id(), $request['body']['reference'], 'Reference = order ID: links the delivery report to the order.' );
		$this->assertStringStartsWith( 'wp-', $request['headers']['x-request-id'] );
		$this->assertStringContainsString( 'Marie Kabongo', $request['body']['body'] );
		$this->assertStringContainsString( '25.00 USD', $request['body']['body'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $request['body']['body'] );

		$notes = $this->notes( wc_get_order( $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( '24381*****78', $notes[0] );
		$this->assertStringContainsString( '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', $notes[0] );
		$this->assertStringNotContainsString( '243812345678', $notes[0], 'Order notes never show the full number.' );

		$saved = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'status:processing' => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10' ), $saved->get_meta( Order_Notifications::SENT_META ) );

		$row = $this->log_rows()[0];
		$this->assertSame( 'order_status', $row['context'] );
		$this->assertSame( (string) $order->get_id(), $row['order_id'] );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( '24381*****78', $row['recipient'] );
		$this->assertSame( '1', $row['test_mode'] );
	}

	public function test_a_second_run_never_sends_the_same_sms_again(): void {
		$order = $this->create_order();
		$this->accepted();

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );
		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertCount( 1, $this->http->requests );
		$this->assertCount( 1, $this->notes( $order ) );
	}

	public function test_api_error_adds_a_failure_note_with_the_error_code(): void {
		$order = $this->create_order();
		$this->http->push_error(
			409,
			'INSUFFICIENT_CREDITS',
			array(
				'available' => 0,
				'required'  => 1,
			)
		);

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$notes = $this->notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'INSUFFICIENT_CREDITS', $notes[0] );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Order_Notifications::SENT_META ) );
		$this->assertCount( 0, $this->pending_actions( Order_Notifications::HOOK_CUSTOMER ), 'A business error is not retried.' );
		$this->assertSame( 'error', $this->log_rows()[0]['status'] );
		$this->assertSame( 'INSUFFICIENT_CREDITS', $this->log_rows()[0]['error_code'] );
	}

	public function test_failure_notes_and_log_keep_the_request_id_for_the_support(): void {
		$order = $this->create_order();
		$this->http->push_error( 422, 'SENDER_ID_NOT_APPROVED', array(), array( 'x-request-id' => 'req-7f3a-support' ) );

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertStringContainsString( 'Request ID: req-7f3a-support', $this->notes( $order )[0] );
		$this->assertSame( 'req-7f3a-support', $this->log_rows()[0]['request_id'] );
	}

	public function test_network_failure_note_has_the_request_id_sent_by_the_plugin(): void {
		$order = $this->create_order();
		$this->http->push_network_error()->push_network_error();

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$sent = $this->http->requests[0]['headers']['x-request-id'];
		$this->assertSame( $sent, $this->http->requests[1]['headers']['x-request-id'], 'Same request ID on the retry.' );
		$this->assertStringContainsString( 'Request ID: ' . $sent, $this->notes( $order )[0] );
		$this->assertSame( $sent, $this->log_rows()[0]['request_id'] );
	}

	public function test_unresolved_idempotency_key_is_a_final_failure_with_the_request_id(): void {
		$order = $this->create_order();
		$this->http->push_error( 409, 'IDEMPOTENCY_KEY_UNRESOLVED', array(), array( 'x-request-id' => 'req-unresolved' ) );

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$notes = $this->notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'IDEMPOTENCY_KEY_UNRESOLVED', $notes[0] );
		$this->assertStringContainsString( 'may have been sent', $notes[0] );
		$this->assertStringContainsString( 'Request ID: req-unresolved', $notes[0] );
		$this->assertCount( 1, $this->http->requests, 'Never retried by the SDK.' );
		$this->assertCount( 0, $this->pending_actions( Order_Notifications::HOOK_CUSTOMER ), 'No new attempt scheduled.' );
		$this->assertSame( 'IDEMPOTENCY_KEY_UNRESOLVED', $this->log_rows()[0]['error_code'] );
		$this->assertSame( 'req-unresolved', $this->log_rows()[0]['request_id'] );
	}

	public function test_suspended_account_note_is_clear_and_not_retried(): void {
		$order = $this->create_order();
		$this->http->push_error( 403, 'ACCOUNT_SUSPENDED', array(), array( 'x-request-id' => 'req-susp' ) );

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$note = $this->notes( $order )[0];
		$this->assertStringContainsString( 'ACCOUNT_SUSPENDED', $note );
		$this->assertStringContainsString( 'suspended', $note );
		$this->assertStringContainsString( 'req-susp', $note );
		$this->assertCount( 1, $this->http->requests );
		$this->assertCount( 0, $this->pending_actions( Order_Notifications::HOOK_CUSTOMER ) );
		$this->assertNotNull( \LabyrintheConnect\Account_Status::suspended() );
	}

	public function test_reference_is_the_order_id_even_with_custom_order_numbers(): void {
		$order = $this->create_order();
		add_filter( 'woocommerce_order_number', static fn(): string => 'FA-2026-0042' );
		$this->accepted();

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );
		remove_all_filters( 'woocommerce_order_number' );

		$this->assertSame( 'order-' . $order->get_id(), $this->http->last()['body']['reference'] );
		$this->assertSame( $order->get_id(), Order_Notifications::order_id_from_reference( 'order-' . $order->get_id() ) );
		$this->assertSame( 0, Order_Notifications::order_id_from_reference( 'order-FA-2026' ) );
		$this->assertSame( 0, Order_Notifications::order_id_from_reference( null ) );
	}

	public function test_network_error_is_retried_later_with_the_same_idempotency_key(): void {
		$order = $this->create_order();
		// Le SDK relance une fois (clé d'idempotence présente), puis l'action est replanifiée.
		$this->http->push_network_error()->push_network_error();

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( $this->http->requests[0]['headers']['idempotency-key'], $this->http->requests[1]['headers']['idempotency-key'] );
		$this->assertTrue( as_has_scheduled_action( Order_Notifications::HOOK_CUSTOMER, array( $order->get_id(), 'processing', 2 ), Order_Notifications::GROUP ) );
		$this->assertStringContainsString( 'CONNECTION_ERROR', $this->notes( $order )[0] );

		// Nouvel essai : même clé, l'API n'enverra jamais deux fois.
		$this->accepted();
		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 2 );
		$this->assertSame( $this->http->requests[0]['headers']['idempotency-key'], $this->http->last()['headers']['idempotency-key'] );
		$this->assertNotSame( '', wc_get_order( $order->get_id() )->get_meta( Order_Notifications::SENT_META ) );
	}

	public function test_maintenance_with_a_return_time_is_rescheduled_at_that_time(): void {
		$order = $this->create_order();
		$this->http->push_error(
			503,
			'FEATURE_DISABLED',
			array(
				'feature'    => 'api.sms_send',
				'blocked_by' => 'api',
				'message'    => 'Maintenance jusqu’à 10 h.',
				'until'      => gmdate( 'c', time() + 7200 ),
			),
			array(
				'retry-after'  => '7200',
				'x-request-id' => 'req-maint',
			)
		);

		$before = time();
		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertCount( 1, $this->http->requests, 'Retry-After above the SDK limit: not retried by the SDK.' );
		$next = as_next_scheduled_action( Order_Notifications::HOOK_CUSTOMER, array( $order->get_id(), 'processing', 2 ), Order_Notifications::GROUP );
		$this->assertIsInt( $next );
		$this->assertGreaterThanOrEqual( $before + 7200, $next, 'Rescheduled at the announced return time.' );
		$this->assertLessThanOrEqual( time() + 7200, $next );
		$note = $this->notes( $order )[0];
		$this->assertStringContainsString( 'FEATURE_DISABLED', $note );
		$this->assertStringContainsString( 'New attempt in 120 minutes', $note );
		$this->assertStringContainsString( 'Request ID: req-maint', $note );
		$row = $this->log_rows()[0];
		$this->assertSame( 'FEATURE_DISABLED', $row['error_code'] );
		$this->assertStringStartsWith( 'Maintenance jusqu’à 10 h.', $row['error_message'] );
		$this->assertStringContainsString( 'Expected back:', $row['error_message'] );
	}

	public function test_maintenance_without_a_return_time_uses_the_usual_delay(): void {
		$order = $this->create_order();
		$this->http->push_error(
			503,
			'FEATURE_DISABLED',
			array(
				'feature'    => 'api',
				'blocked_by' => 'api',
				'message'    => null,
				'until'      => null,
			)
		);

		$before = time();
		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertCount( 1, $this->http->requests, 'Never retried by the SDK without Retry-After.' );
		$next = as_next_scheduled_action( Order_Notifications::HOOK_CUSTOMER, array( $order->get_id(), 'processing', 2 ), Order_Notifications::GROUP );
		$this->assertIsInt( $next );
		$this->assertGreaterThanOrEqual( $before + 5 * MINUTE_IN_SECONDS, $next );
		$this->assertLessThanOrEqual( time() + 5 * MINUTE_IN_SECONDS, $next );
		$this->assertStringContainsString( 'temporarily unavailable', $this->log_rows()[0]['error_message'] );
	}

	public function test_last_attempt_is_not_rescheduled(): void {
		$order = $this->create_order();
		$this->http->push_error( 503, 'SERVICE_UNAVAILABLE' )->push_error( 503, 'SERVICE_UNAVAILABLE' );

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', Order_Notifications::MAX_ATTEMPTS );

		$this->assertCount( 0, $this->pending_actions( Order_Notifications::HOOK_CUSTOMER ) );
		$this->assertStringContainsString( 'failed', $this->notes( $order )[0] );
	}

	public function test_order_without_phone_gets_a_note_and_no_request(): void {
		$order = $this->create_order( '' );

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertSame( array(), $this->http->requests );
		$this->assertStringContainsString( 'no phone number', $this->notes( $order )[0] );
	}

	public function test_accents_are_removed_by_the_api_when_enabled(): void {
		$this->use_settings(
			array(
				'strip_accents' => true,
				'wc_statuses'   => array(
					'completed' => array(
						'enabled'  => true,
						'template' => 'Bonjour {customer_first_name}, votre commande n°{order_number} est expédiée ! « Merci »',
					),
				),
			)
		);
		$order = $this->create_order();
		$this->accepted();

		$this->notifications->send_customer_sms( $order->get_id(), 'completed', 1 );

		$request = $this->http->last();
		$this->assertSame( 'Bonjour Marie, votre commande n°' . $order->get_order_number() . ' est expédiée ! « Merci »', $request['body']['body'], 'The text is sent as is.' );
		$this->assertTrue( $request['body']['strip_accents'], 'The API removes the accents (strip_accents).' );
	}

	public function test_local_number_uses_the_billing_country_calling_code(): void {
		$order = $this->create_order( '06 12 34 56 78' );
		$order->set_billing_country( 'FR' );
		$order->save();
		$this->accepted();

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertSame( '+33612345678', $this->http->last()['body']['recipient'] );
	}

	public function test_short_foreign_number_keeps_its_country_code(): void {
		$order = $this->create_order( '+248 123 4567' );
		$this->accepted();

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		// BACKEND-TODO n° 32 : sans « + », l'API lirait 2481234567 comme un numéro local RDC.
		$this->assertSame( '+2481234567', $this->http->last()['body']['recipient'] );
	}

	public function test_tracking_variables_come_from_shipment_tracking(): void {
		$this->use_settings(
			array(
				'wc_statuses' => array(
					'completed' => array(
						'enabled'  => true,
						'template' => 'Suivi {tracking_number} : {tracking_url}',
					),
				),
			)
		);
		$order = $this->create_order();
		$order->update_meta_data(
			'_wc_shipment_tracking_items',
			array(
				array(
					'tracking_number'      => 'CD123',
					'custom_tracking_link' => 'https://track.example.com/CD123',
				),
			)
		);
		$order->save();
		$this->accepted();

		$this->notifications->send_customer_sms( $order->get_id(), 'completed', 1 );

		$this->assertSame( 'Suivi CD123 : https://track.example.com/CD123', $this->http->last()['body']['body'] );
	}

	public function test_pending_sms_is_only_sent_if_the_order_is_still_pending(): void {
		$this->use_settings( array( 'wc_statuses' => array( 'pending' => array( 'enabled' => true ) ) ) );
		$order = $this->create_order();
		$this->notifications->on_order_placed( $order->get_id() );
		$this->assertTrue( as_has_scheduled_action( Order_Notifications::HOOK_CUSTOMER, array( $order->get_id(), 'pending', 1 ), Order_Notifications::GROUP ) );

		$order->set_status( 'processing' );
		$order->save();
		$this->notifications->send_customer_sms( $order->get_id(), 'pending', 1 );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_shop_manager_gets_one_sms_per_number(): void {
		$this->use_settings(
			array(
				'wc_admin_enabled' => true,
				'wc_admin_phones'  => '+243990000001, +243990000002',
			)
		);
		$order = $this->create_order();
		$this->notifications->on_order_placed( $order );
		$this->assertTrue( as_has_scheduled_action( Order_Notifications::HOOK_ADMIN, array( $order->get_id(), 1 ), Order_Notifications::GROUP ) );

		$this->accepted();
		$this->http->push( 202, array( 'data' => array( self::api_message( array( 'id' => 'second-id' ) ) ) ) );
		$this->notifications->send_admin_sms( $order->get_id(), 1 );
		$this->notifications->send_admin_sms( $order->get_id(), 1 );

		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( '+243990000001', $this->http->requests[0]['body']['recipient'] );
		$this->assertSame( '+243990000002', $this->http->requests[1]['body']['recipient'] );
		$this->assertNotSame( $this->http->requests[0]['headers']['idempotency-key'], $this->http->requests[1]['headers']['idempotency-key'] );
		$this->assertStringContainsString( '0812345678', (string) $this->http->requests[0]['body']['body'], 'The shop manager sees the customer phone.' );
		$this->assertSame( 'admin_new_order', $this->log_rows()[0]['context'] );
	}

	public function test_filter_can_cancel_an_order_sms(): void {
		$order = $this->create_order();
		add_filter( 'labyrinthe_connect_send_order_sms', '__return_false' );

		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		remove_filter( 'labyrinthe_connect_send_order_sms', '__return_false' );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_works_with_the_order_storage_in_use(): void {
		// HPOS (wc_orders) ou articles : les métadonnées passent par l'API CRUD de WooCommerce.
		$order = $this->create_order();
		$this->accepted();
		$this->notifications->send_customer_sms( $order->get_id(), 'processing', 1 );

		$this->assertIsArray( wc_get_order( $order->get_id() )->get_meta( Order_Notifications::SENT_META ) );
		// HPOS par défaut, articles avec LABYRINTHE_TEST_HPOS=no.
		$this->assertSame( 'no' !== getenv( 'LABYRINTHE_TEST_HPOS' ), \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	}
}
