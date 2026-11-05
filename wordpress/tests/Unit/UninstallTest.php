<?php
/**
 * Désinstallation : toutes les données du plugin disparaissent.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Cron;
use LabyrintheConnect\Message_Log;
use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Schema;
use LabyrintheConnect\Sender_Ids;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Tests\Support\TestCase;
use LabyrintheConnect\Uninstaller;
use LabyrintheConnect\WooCommerce\Order_Notifications;

final class UninstallTest extends TestCase {

	public function tear_down(): void {
		// Les tables supprimées pour de vrai sont recréées (vraies tables, pas temporaires) pour les tests suivants.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		Schema::install();
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		parent::tear_down();
	}

	public function test_uninstall_removes_everything(): void {
		global $wpdb;

		$this->configure();
		update_option( 'labyrinthe_connect_last_ping', time() );
		set_transient( Sender_Ids::CACHE_KEY, array(), HOUR_IN_SECONDS );
		set_transient( 'labyrinthe_otp_' . str_repeat( 'a', 64 ), array( 'pending' => array() ), HOUR_IN_SECONDS );
		( new Message_Log() )->insert( array( 'message_id' => 'x' ) );
		$user_id = self::factory()->user->create();
		Phone_Users::set( $user_id, '243812345678' );
		$order = wc_create_order();
		$order->update_meta_data( Order_Notifications::SENT_META, array( 'status:processing' => 'x' ) );
		$order->save();
		as_enqueue_async_action( Order_Notifications::HOOK_CUSTOMER, array( $order->get_id(), 'processing', 1 ), Order_Notifications::GROUP );
		Cron::schedule();

		// DROP TABLE réel (la suite de tests le transforme sinon en DROP TEMPORARY TABLE).
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'labyrinthe-connect/labyrinthe-connect.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( 'labyrinthe_connect_last_ping' ) );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%transient%labyrinthe_%' ) ) );
		$this->assertSame( '', get_user_meta( $user_id, Phone_Users::META, true ) );
		wp_cache_flush();
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Order_Notifications::SENT_META ) );
		$this->assertFalse( as_has_scheduled_action( Order_Notifications::HOOK_CUSTOMER ) );
		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::log_table() ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::events_table() ) ) );
		$this->assertTrue( class_exists( Uninstaller::class ) );
	}
}
