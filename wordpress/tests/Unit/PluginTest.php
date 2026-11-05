<?php
/**
 * Chargement du plugin : SDK préfixé, pages publiques sans appel à l'API, administration.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Admin\Settings_Page;
use LabyrintheConnect\Crypto;
use LabyrintheConnect\Otp\Login;
use LabyrintheConnect\Otp\Phone_Users;
use LabyrintheConnect\Plugin;
use LabyrintheConnect\Sender_Ids;
use LabyrintheConnect\Client_Factory;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Sms_Text;
use LabyrintheConnect\Templates;
use LabyrintheConnect\Tests\Support\TestCase;

final class PluginTest extends TestCase {

	public function test_the_sdk_is_loaded_from_the_prefixed_copy(): void {
		$client = new \ReflectionClass( \LabyrintheConnect\Vendor\Labyrinthe\Connect\Client::class );
		$guzzle = new \ReflectionClass( \LabyrintheConnect\Vendor\GuzzleHttp\Psr7\HttpFactory::class );

		$this->assertStringContainsString( 'vendor-prefixed', str_replace( '\\', '/', (string) $client->getFileName() ) );
		$this->assertStringContainsString( 'vendor-prefixed', str_replace( '\\', '/', (string) $guzzle->getFileName() ) );
		$this->assertFalse( class_exists( 'Labyrinthe\Connect\Client', false ), 'The unprefixed SDK is never loaded by the plugin.' );
	}

	public function test_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ) );
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( Plugin::class, 'declare_woocommerce_compatibility' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed' ) );
		$this->assertTrue( shortcode_exists( Login::SHORTCODE ) );
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'labyrinthe-connect/otp-login' ) );
	}


	public function test_public_pages_never_call_the_api(): void {
		$settings = $this->configure(
			array(
				'otp_login_enabled'    => true,
				'otp_register_enabled' => true,
			)
		);
		$login    = new Login( $settings, new Phone_Users( $settings ) );

		ob_start();
		$login->login_form();
		echo $login->shortcode( array( 'redirect' => '/my-account/' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo do_blocks( '<!-- wp:labyrinthe-connect/otp-login {"title":"Connexion"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		do_action( 'wp_head' );
		do_action( 'wp_footer' );
		$html = (string) ob_get_clean();

		$this->assertSame( array(), $this->http->requests );
		$this->assertStringContainsString( 'labyrinthe-otp', $html );
		$this->assertStringContainsString( 'Connexion', $html );
		$this->assertStringNotContainsString( self::API_KEY, $html );
		$this->assertStringNotContainsString( 'otp_id', $html );
		$this->assertTrue( wp_script_is( 'labyrinthe-connect-otp', 'enqueued' ) );
	}

	public function test_shortcode_is_empty_when_sms_login_is_disabled(): void {
		$settings = $this->configure();

		$this->assertSame( '', ( new Login( $settings, new Phone_Users( $settings ) ) )->shortcode( array() ) );
	}

	public function test_settings_page_never_prints_the_secrets(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$settings = $this->configure( array( 'webhook_secret' => Crypto::encrypt( 'whsec_super_secret_value' ) ) );
		set_transient(
			Sender_Ids::CACHE_KEY,
			array(
				array(
					'value'      => 'MABOUTIQUE',
					'is_default' => true,
				),
			),
			HOUR_IN_SECONDS
		);
		$page = new Settings_Page( $settings, new Sender_Ids( new Client_Factory( $settings ) ) );

		foreach ( array( Settings::TAB_GENERAL, Settings::TAB_WOOCOMMERCE, Settings::TAB_OTP ) as $tab ) {
			$_GET['tab'] = $tab;
			ob_start();
			$page->render();
			$html = (string) ob_get_clean();

			$this->assertStringNotContainsString( self::API_KEY, $html );
			$this->assertStringNotContainsString( 'whsec_super_secret_value', $html );
			$this->assertStringNotContainsString( (string) get_option( Settings::OPTION )['api_key'], $html, 'Not even the encrypted value.' );
		}
		unset( $_GET['tab'] );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'lsk_test_…ABCD', $html );
		$this->assertStringContainsString( 'MABOUTIQUE', $html );
		$this->assertStringContainsString( '/labyrinthe/v1/webhook', $html );
		$this->assertSame( array(), $this->http->requests, 'Sender IDs come from the cache.' );
	}

	public function test_settings_page_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$settings = $this->configure();
		$page     = new Settings_Page( $settings, new Sender_Ids( new Client_Factory( $settings ) ) );

		$this->expectException( \WPDieException::class );
		$page->render();
	}

	public function test_french_sites_get_the_french_translation(): void {
		$plugin = Plugin::instance();

		$this->assertSame( 'fr_FR', $plugin->french_fallback( 'fr_BE', 'labyrinthe-connect' ) );
		$this->assertSame( 'fr_FR', $plugin->french_fallback( 'fr_FR', 'labyrinthe-connect' ) );
		$this->assertSame( 'en_GB', $plugin->french_fallback( 'en_GB', 'labyrinthe-connect' ) );
		$this->assertSame( 'fr_BE', $plugin->french_fallback( 'fr_BE', 'other-plugin' ) );
		$this->assertFileExists( LABYRINTHE_CONNECT_DIR . 'languages/labyrinthe-connect-fr_FR.mo' );
	}

	public function test_french_translation_loads_and_sms_templates_stay_ascii(): void {
		unload_textdomain( 'labyrinthe-connect' );
		$this->assertTrue( load_textdomain( 'labyrinthe-connect', LABYRINTHE_CONNECT_DIR . 'languages/labyrinthe-connect-fr_FR.mo' ) );

		try {
			$this->assertSame( 'Journal des SMS', __( 'SMS log', 'labyrinthe-connect' ) );
			foreach ( array( 'pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'custom' ) as $status ) {
				$template = Templates::order_status( $status );
				$this->assertStringStartsWith( 'Bonjour', $template );
				$this->assertSame( 'gsm7', Sms_Text::analyse( $template )['encoding'], 'French default templates stay in GSM-7: ' . $status );
			}
			$this->assertSame( 'gsm7', Sms_Text::analyse( Templates::admin_new_order() )['encoding'] );
			$this->assertSame( 'gsm7', Sms_Text::analyse( Templates::test_message() )['encoding'] );
		} finally {
			unload_textdomain( 'labyrinthe-connect' );
		}
	}
}
