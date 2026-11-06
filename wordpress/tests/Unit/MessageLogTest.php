<?php
/**
 * Journal des SMS : écriture, recherche, purge, page d'administration.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Admin\Log_List_Table;
use LabyrintheConnect\Cron;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Schema;
use LabyrintheConnect\Tests\Support\TestCase;
use LabyrintheConnect\Webhook\Event_Store;

final class MessageLogTest extends TestCase {

	private Message_Log $log;

	public function set_up(): void {
		parent::set_up();
		$this->log = new Message_Log();
	}

	public function test_test_sms_is_logged_with_a_masked_number(): void {
		$settings = $this->configure();
		$this->http->push( 202, array( 'data' => array( self::api_message() ) ) );

		$result = $this->sender( $settings )->send( '0812345678', 'Hello', array( 'context' => 'test' ) );

		$this->assertTrue( $result->success );
		$this->assertTrue( $result->test_mode );
		$row = $this->log->find( $result->log_id );
		$this->assertSame( '24381*****78', $row['recipient'] );
		$this->assertSame( 'test', $row['context'] );
		$this->assertSame( '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10', $row['message_id'] );
		$this->assertStringNotContainsString( 'Hello', (string) wp_json_encode( $row ), 'Message texts are never stored.' );
		$this->assertStringNotContainsString( '243812345678', (string) wp_json_encode( $row ) );
	}

	public function test_invalid_number_is_logged_as_not_sent(): void {
		$settings = $this->configure();

		$result = $this->sender( $settings )->send( '12', 'Hello', array( 'context' => 'test' ) );

		$this->assertFalse( $result->success );
		$this->assertSame( 'INVALID_PHONE', $result->error_code );
		$this->assertSame( array(), $this->http->requests );
		$this->assertSame( 'error', $this->log->find( $result->log_id )['status'] );
	}

	public function test_missing_key_is_reported(): void {
		$result = $this->sender( new \LabyrintheConnect\Settings() )->send( '0812345678', 'Hello', array( 'context' => 'test' ) );

		$this->assertSame( 'NOT_CONFIGURED', $result->error_code );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_query_filters_search_and_paginates(): void {
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->log->insert(
				array(
					'message_id' => 'msg-' . $i,
					'recipient'  => '24381*****78',
					'context'    => 0 === $i % 5 ? 'test' : 'order_status',
					'order_id'   => $i,
					'status'     => 0 === $i % 2 ? 'delivered' : 'queued',
				)
			);
		}

		$this->assertSame( 25, $this->log->query( array() )['total'] );
		$this->assertCount( 10, $this->log->query( array( 'per_page' => 10 ) )['items'] );
		$this->assertCount(
			5,
			$this->log->query(
				array(
					'per_page' => 10,
					'page'     => 3,
				)
			)['items']
		);
		$this->assertSame( 12, $this->log->query( array( 'status' => 'delivered' ) )['total'] );
		$this->assertSame( 5, $this->log->query( array( 'context' => 'test' ) )['total'] );
		$this->assertSame( '7', $this->log->query( array( 'search' => '#7' ) )['items'][0]['order_id'] );
		$this->assertSame( 'msg-9', $this->log->query( array( 'search' => 'msg-9' ) )['items'][0]['message_id'] );
		$this->assertSame( 0, $this->log->query( array( 'search' => "' OR 1=1 --" ) )['total'], 'Search values are prepared.' );
		$this->assertSame(
			'1',
			$this->log->query(
				array(
					'orderby' => 'order_id; DROP TABLE x',
					'order'   => 'asc',
				)
			)['items'][0]['order_id']
		);
	}

	public function test_purge_removes_old_rows_only(): void {
		global $wpdb;

		$old = $this->log->insert( array( 'message_id' => 'old' ) );
		$new = $this->log->insert( array( 'message_id' => 'new' ) );
		$wpdb->update( Schema::log_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );

		$this->assertSame( 0, $this->log->purge( 0 ), '0 keeps everything.' );
		$this->assertSame( 1, $this->log->purge( 90 ) );
		$this->assertNull( $this->log->find( $old ) );
		$this->assertNotNull( $this->log->find( $new ) );
	}

	public function test_daily_task_uses_the_retention_setting(): void {
		global $wpdb;

		$settings = $this->configure( array( 'log_retention_days' => 30 ) );
		$old      = $this->log->insert( array( 'message_id' => 'old' ) );
		$wpdb->update( Schema::log_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
		$events = new Event_Store();
		$events->claim( 'old-event', 'test.ping' );
		$wpdb->update( Schema::events_table(), array( 'received_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ), array( 'event_id' => 'old-event' ) );

		$this->assertSame(
			array(
				'log'    => 1,
				'events' => 1,
			),
			Cron::run( $settings, $this->log, $events )
		);
		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ), 'Scheduled on activation.' );
	}

	public function test_request_id_is_stored_searchable_and_shown(): void {
		$this->log->insert(
			array(
				'message_id'    => '',
				'recipient'     => '24381*****78',
				'context'       => 'test',
				'status'        => 'error',
				'error_code'    => 'SERVICE_UNAVAILABLE',
				'error_message' => 'Indisponible',
				'request_id'    => 'wp-6b1f2c3d-support',
			)
		);
		$this->log->insert( array( 'message_id' => 'other' ) );

		$found = $this->log->query( array( 'search' => 'wp-6b1f2c3d-support' ) );
		$this->assertSame( 1, $found['total'] );
		$this->assertSame( 'wp-6b1f2c3d-support', $found['items'][0]['request_id'] );

		set_current_screen( 'toplevel_page_labyrinthe-connect' );
		$table = new Log_List_Table();
		$table->prepare_items();
		ob_start();
		$table->display();
		$this->assertStringContainsString( 'Request ID: wp-6b1f2c3d-support', (string) ob_get_clean() );
	}

	public function test_schema_upgrade_adds_the_request_id_column(): void {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', \LabyrintheConnect\Schema::log_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( 'request_id', $columns );
		$this->assertSame( '2', get_option( \LabyrintheConnect\Schema::VERSION_OPTION ) );
	}

	public function test_list_table_shows_masked_numbers_and_escapes_values(): void {
		$this->log->insert(
			array(
				'message_id'    => 'msg-1',
				'recipient'     => '24381*****78',
				'context'       => 'test',
				'status'        => 'error',
				'error_code'    => 'INSUFFICIENT_CREDITS',
				'error_message' => '<script>alert(1)</script>',
			)
		);
		set_current_screen( 'toplevel_page_labyrinthe-connect' );
		$table = new Log_List_Table();
		$table->prepare_items();

		ob_start();
		$table->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '24381*****78', $html );
		$this->assertStringContainsString( 'INSUFFICIENT_CREDITS', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'Not sent', $html );
	}
}
