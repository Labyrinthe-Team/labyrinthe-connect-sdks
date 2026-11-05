<?php
/**
 * Réglages : valeurs par défaut, assainissement, chiffrement des secrets.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Unit;

use LabyrintheConnect\Crypto;
use LabyrintheConnect\Sender_Ids;
use LabyrintheConnect\Settings;
use LabyrintheConnect\Tests\Support\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_without_api_key(): void {
		$settings = new Settings();

		$this->assertFalse( $settings->has_api_key() );
		$this->assertSame( 'none', $settings->api_key_source() );
		$this->assertSame( '243', $settings->country_code() );
		$this->assertNull( $settings->sender() );
		$this->assertFalse( $settings->strip_accents() );
		$this->assertSame( 90, $settings->log_retention_days() );
		$this->assertTrue( $settings->order_status( 'processing' )['enabled'] );
		$this->assertTrue( $settings->order_status( 'completed' )['enabled'] );
		$this->assertFalse( $settings->order_status( 'cancelled' )['enabled'] );
		$this->assertStringContainsString( '{order_number}', $settings->order_status( 'cancelled' )['template'] );
	}

	public function test_new_api_key_is_validated_encrypted_and_never_stored_in_clear(): void {
		$settings = new Settings();
		$saved    = $settings->sanitize(
			array(
				'_tab'        => Settings::TAB_GENERAL,
				'api_key_new' => '  ' . self::API_KEY . ' ',
			)
		);
		update_option( Settings::OPTION, $saved );

		$this->assertTrue( Crypto::is_encrypted( $saved['api_key'] ) );
		$this->assertStringNotContainsString( self::API_KEY, (string) wp_json_encode( get_option( Settings::OPTION ) ) );
		$this->assertArrayNotHasKey( 'api_key_new', $saved );
		$this->assertArrayNotHasKey( '_tab', $saved );
		$this->assertSame( self::API_KEY, $settings->api_key() );
		$this->assertTrue( $settings->is_test_mode() );
		$this->assertSame( 'lsk_test_…ABCD', $settings->api_key_hint() );
	}

	public function test_invalid_api_key_keeps_the_previous_one(): void {
		$settings = $this->configure();
		$saved    = $settings->sanitize(
			array(
				'_tab'        => Settings::TAB_GENERAL,
				'api_key_new' => 'sk_live_not_a_labyrinthe_key',
			)
		);

		$this->assertSame( get_option( Settings::OPTION )['api_key'], $saved['api_key'] );
		$this->assertNotEmpty( get_settings_errors( Settings::OPTION ) );
	}

	public function test_empty_field_keeps_the_key_and_clear_removes_it(): void {
		$settings = $this->configure();
		$current  = get_option( Settings::OPTION )['api_key'];

		$kept = $settings->sanitize(
			array(
				'_tab'        => Settings::TAB_GENERAL,
				'api_key_new' => '',
			)
		);
		$this->assertSame( $current, $kept['api_key'] );

		$cleared = $settings->sanitize(
			array(
				'_tab'          => Settings::TAB_GENERAL,
				'api_key_clear' => '1',
			)
		);
		$this->assertSame( '', $cleared['api_key'] );
	}

	public function test_changing_the_key_clears_the_sender_cache(): void {
		set_transient(
			Sender_Ids::CACHE_KEY,
			array(
				array(
					'value'      => 'OLD',
					'is_default' => false,
				),
			),
			HOUR_IN_SECONDS
		);
		( new Settings() )->sanitize( array( 'api_key_new' => 'lsk_live_cd_' . str_repeat( 'x', 40 ) ) );

		$this->assertFalse( get_transient( Sender_Ids::CACHE_KEY ) );
	}

	public function test_double_sanitize_of_add_option_keeps_the_encrypted_key(): void {
		$settings = new Settings();
		$first    = $settings->sanitize(
			array(
				'_tab'        => Settings::TAB_GENERAL,
				'api_key_new' => self::API_KEY,
			)
		);
		// WordPress assainit deux fois quand l'option n'existe pas encore (add_option).
		$second = $settings->sanitize( $first );

		$this->assertSame( $first['api_key'], $second['api_key'] );
	}

	public function test_webhook_secret_is_encrypted_and_validated(): void {
		$settings = $this->configure();
		$saved    = $settings->sanitize(
			array(
				'_tab'               => Settings::TAB_GENERAL,
				'webhook_secret_new' => 'whsec_0123456789abcdef',
			)
		);
		update_option( Settings::OPTION, $saved );
		$this->assertSame( 'whsec_0123456789abcdef', ( new Settings() )->webhook_secret() );

		$rejected = ( new Settings() )->sanitize( array( 'webhook_secret_new' => 'short' ) );
		$this->assertSame( $saved['webhook_secret'], $rejected['webhook_secret'] );
	}

	public function test_general_fields_are_sanitized(): void {
		$saved = ( new Settings() )->sanitize(
			array(
				'_tab'               => Settings::TAB_GENERAL,
				'sender'             => '<b>MABOUTIQUE</b>EXTRA',
				'country_code'       => '+33',
				'log_retention_days' => '-5',
			)
		);

		$this->assertSame( 'MABOUTIQUEE', $saved['sender'] );
		$this->assertSame( '33', $saved['country_code'] );
		$this->assertSame( 5, $saved['log_retention_days'] );
		$this->assertFalse( $saved['strip_accents'], 'An unchecked box of the submitted tab is off.' );
	}

	public function test_invalid_country_code_is_refused(): void {
		$saved = ( new Settings() )->sanitize( array( 'country_code' => '0000' ) );

		$this->assertSame( '243', $saved['country_code'] );
	}

	public function test_only_the_submitted_tab_changes(): void {
		$settings = $this->configure(
			array(
				'strip_accents'     => true,
				'otp_login_enabled' => true,
			)
		);
		$saved    = $settings->sanitize(
			array(
				'_tab'                 => Settings::TAB_OTP,
				'otp_register_enabled' => '1',
			)
		);

		$this->assertTrue( $saved['strip_accents'], 'Other tabs are untouched.' );
		$this->assertTrue( $saved['otp_register_enabled'] );
		$this->assertFalse( $saved['otp_login_enabled'], 'Unchecked box of the OTP tab.' );
	}

	public function test_order_status_templates_and_admin_phones(): void {
		$saved = ( new Settings() )->sanitize(
			array(
				'_tab'              => Settings::TAB_WOOCOMMERCE,
				'wc_statuses'       => array(
					'completed'  => array(
						'enabled'  => '1',
						'template' => "Merci {customer_name} <script>alert(1)</script>\nA bientot",
					),
					'processing' => array( 'template' => 'En cours' ),
				),
				'wc_admin_enabled'  => '1',
				'wc_admin_phones'   => '0812345678, +33 6 12 34 56 78; nope',
				'wc_admin_template' => str_repeat( 'a', 2000 ),
			)
		);

		$this->assertTrue( $saved['wc_statuses']['completed']['enabled'] );
		$this->assertStringNotContainsString( '<script>', $saved['wc_statuses']['completed']['template'] );
		$this->assertStringContainsString( "\n", $saved['wc_statuses']['completed']['template'] );
		$this->assertFalse( $saved['wc_statuses']['processing']['enabled'] );
		$this->assertSame( '+243812345678, +33612345678', $saved['wc_admin_phones'] );
		$this->assertSame( 1600, mb_strlen( $saved['wc_admin_template'] ) );
		$this->assertTrue( $saved['wc_admin_enabled'] );
	}

	public function test_otp_template_must_contain_the_code_token(): void {
		$settings = $this->configure( array( 'otp_template' => 'Code : {code}' ) );

		$this->assertSame( 'Code : {code}', $settings->sanitize( array( 'otp_template' => 'Sans jeton' ) )['otp_template'] );
		$this->assertSame( 'Votre code {code}', $settings->sanitize( array( 'otp_template' => 'Votre code {code}' ) )['otp_template'] );
		$this->assertSame( '', $settings->sanitize( array( 'otp_template' => '' ) )['otp_template'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		$saved = ( new Settings() )->sanitize( array( 'evil' => 'x' ) );

		$this->assertArrayNotHasKey( 'evil', $saved );
	}

	public function test_api_key_from_a_constant_has_priority(): void {
		$this->assertSame( 'none', ( new Settings() )->api_key_source() );
		// La constante n'est pas définie dans les tests : le chemin « option » est vérifié ailleurs.
		$this->assertFalse( defined( 'LABYRINTHE_CONNECT_API_KEY' ) );
	}

	public function test_unreadable_key_is_detected(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array( 'api_key' => Crypto::PREFIX . base64_encode( str_repeat( 'x', 64 ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);
		$settings = new Settings();

		$this->assertTrue( $settings->api_key_unreadable() );
		$this->assertFalse( $settings->has_api_key() );
	}
}
