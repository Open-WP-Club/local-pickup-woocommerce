<?php

class LPS_Test_FeaturesUtil {
	public static $declarations = array();
	public static function declare_compatibility( ...$args ) { self::$declarations[] = $args; }
}

final class PluginTest extends LPS_TestCase {
	public function test_registration_preserves_other_methods_and_deduplicates_pickup_id(): void {
		$plugin = LPS_Plugin::instance();
		$this->assertSame( array( 'flat_rate' => 'WC_Shipping_Flat_Rate', 'lps_local_pickup' => 'LPS_Shipping_Method' ), $plugin->register_shipping_method( array( 'flat_rate' => 'WC_Shipping_Flat_Rate' ) ) );
		$this->assertSame( array( 'local_pickup', 'lps_local_pickup' ), $plugin->register_as_local_pickup( array( 'local_pickup', 'lps_local_pickup' ) ) );
	}

	public function test_pickup_ui_is_enabled_without_losing_existing_settings(): void {
		$plugin = LPS_Plugin::instance();
		$this->assertSame( array( 'enabled' => 'yes' ), $plugin->enable_block_pickup_ui( false ) );
		$this->assertSame( array( 'enabled' => 'yes', 'title' => 'Collect' ), $plugin->enable_block_pickup_ui( array( 'enabled' => 'no', 'title' => 'Collect' ) ) );
	}

	private function setZones(): void {
		WC_Shipping_Method::$test_instance_settings = array(
			7 => array( 'title' => 'Collect here', 'show_price' => 'no' ),
			8 => array( 'title' => 'Other zone' ),
		);
		WC_Shipping_Zones::$zones = array( array( 'zone_id' => 1 ) );
		WC_Shipping_Zone::$methods = array(
			1 => array( new LPS_Shipping_Method( 7 ), (object) array( 'id' => 'flat_rate' ) ),
			0 => array( new LPS_Shipping_Method( 8 ) ),
		);
	}

	public function test_frontend_settings_include_all_zones_and_rest_of_world(): void {
		$this->setZones();
		$this->assertSame( array(
			'titles' => array( 7 => 'Collect here', 8 => 'Other zone' ),
			'show_price' => array( 7 => false, 8 => true ),
		), LPS_Plugin::instance()->get_pickup_method_settings() );
	}

	/** @dataProvider checkoutPages */
	public function test_assets_load_only_on_checkout_before_order_received( $checkout, $received, $loaded ): void {
		$GLOBALS['lps_test_is_checkout'] = $checkout;
		$GLOBALS['lps_test_order_received'] = $received;
		$this->setZones();
		LPS_Plugin::instance()->enqueue_checkout_assets();
		$this->assertCount( $loaded ? 1 : 0, $GLOBALS['lps_test_scripts'] );
		$this->assertCount( $loaded ? 1 : 0, $GLOBALS['lps_test_styles'] );
		$this->assertCount( $loaded ? 1 : 0, $GLOBALS['lps_test_localized'] );
		if ( $loaded ) {
			$this->assertSame( array( 'lps-checkout', LPS_URL . 'assets/js/checkout.js', array(), LPS_VERSION, true ), $GLOBALS['lps_test_scripts'][0] );
			$this->assertSame( 'lpsCheckout', $GLOBALS['lps_test_localized'][0][1] );
			$this->assertSame( array( 7 => false, 8 => true ), $GLOBALS['lps_test_localized'][0][2]['showPrice'] );
		}
	}

	public static function checkoutPages(): array {
		return array( array( true, false, true ), array( false, false, false ), array( true, true, false ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_declares_hpos_and_block_checkout_compatibility_when_woocommerce_api_exists(): void {
		class_alias( LPS_Test_FeaturesUtil::class, 'Automattic\WooCommerce\Utilities\FeaturesUtil' );
		LPS_Plugin::instance()->declare_compatibility();
		$this->assertSame( array(
			array( 'custom_order_tables', LPS_FILE, true ), array( 'cart_checkout_blocks', LPS_FILE, true ),
		), LPS_Test_FeaturesUtil::$declarations );
	}

	public function test_order_hooks_wire_validation_notifications_and_display(): void {
		LPS_Order::init();
		foreach ( array(
			'woocommerce_store_api_checkout_update_order_meta' => 'validate_and_save',
			'woocommerce_email_recipient_new_order' => 'add_location_notify_recipient',
			'woocommerce_customer_taxable_address' => 'pickup_taxable_address',
			'woocommerce_order_details_after_order_table' => 'frontend_order_details',
		) as $hook => $method ) {
			$this->assertContains( array( LPS_Order::class, $method ), $GLOBALS['lps_test_hooks'][ $hook ] );
		}
	}
}
