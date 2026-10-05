<?php

final class ShippingMethodTest extends LPS_TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['lps_test_posts'] = array(
			lps_test_location( 10, 'Central Store' ),
			lps_test_location( 20, 'West Store' ),
			lps_test_location( 30, 'East Store' ),
		);

		$GLOBALS['lps_test_post_meta'] = array(
			10 => $this->locationMeta( '10 Main Street', '', 'taxable' ),
			20 => $this->locationMeta( '20 West Street', '4.50', 'none' ),
			30 => $this->locationMeta( '30 East Street', '2.00', 'taxable' ),
		);

		WC_Shipping_Method::$test_instance_settings = array();
	}

	public function test_default_location_is_the_first_rate(): void {
		WC_Shipping_Method::$test_instance_settings[7] = array(
			'locations'        => array(),
			'default_location' => '20',
		);

		$method = new LPS_Shipping_Method( 7 );
		$method->calculate_shipping();

		$this->assertSame(
			array( 'lps_local_pickup:7:20', 'lps_local_pickup:7:10', 'lps_local_pickup:7:30' ),
			array_column( $method->rates, 'id' )
		);
	}

	public function test_zone_only_returns_allowed_locations(): void {
		WC_Shipping_Method::$test_instance_settings[8] = array(
			'locations'        => array( '10', '30' ),
			'default_location' => '20',
		);

		$method = new LPS_Shipping_Method( 8 );
		$method->calculate_shipping();

		$this->assertSame(
			array( 'lps_local_pickup:8:10', 'lps_local_pickup:8:30' ),
			array_column( $method->rates, 'id' )
		);
	}

	public function test_rate_contains_location_price_tax_status_and_snapshot(): void {
		WC_Shipping_Method::$test_instance_settings[9] = array(
			'locations'        => array( '20' ),
			'default_location' => '',
		);

		$method = new LPS_Shipping_Method( 9 );
		$method->calculate_shipping();

		$this->assertCount( 1, $method->rates );
		$this->assertSame( 4.5, $method->rates[0]['cost'] );
		$this->assertSame( 'none', $method->rates[0]['tax_status'] );
		$this->assertSame( 20, $method->rates[0]['meta_data']['_lps_location_id'] );
		$this->assertSame( 'West Store', $method->rates[0]['meta_data']['_lps_location_name'] );
		$this->assertSame( '20 West Street, 1000, Sofia, Sofia City, Bulgaria', $method->rates[0]['meta_data']['_lps_location_address'] );
	}

	public function test_free_rate_has_zero_cost_and_each_rate_keeps_its_own_tax_status(): void {
		$method = new LPS_Shipping_Method( 7 );
		$package = array( 'destination' => array( 'country' => 'BG' ) );
		$method->calculate_shipping( $package );
		$this->assertSame( array( 0.0, 4.5, 2.0 ), array_column( $method->rates, 'cost' ) );
		$this->assertSame( array( 'taxable', 'none', 'taxable' ), array_column( $method->rates, 'tax_status' ) );
		$this->assertSame( $package, $method->rates[0]['package'] );
		$this->assertSame( array(
			'address_1' => '10 Main Street', 'city' => 'Sofia', 'state' => 'Sofia City', 'postcode' => '1000', 'country' => 'BG',
		), $method->rates[0]['meta_data']['_pickup_location_address'] );
		$this->assertSame( '09:00–18:00', $method->rates[0]['meta_data']['_lps_location_hours'] );
		$this->assertSame( '+359 2 000 0000', $method->rates[0]['meta_data']['_lps_location_phone'] );
	}

	public function test_disabled_locations_never_produce_rates_even_when_default(): void {
		$GLOBALS['lps_test_post_meta'][20]['_lps_enabled'] = 'no';
		WC_Shipping_Method::$test_instance_settings[7] = array( 'default_location' => '20' );
		$method = new LPS_Shipping_Method( 7 );
		$method->calculate_shipping();
		$this->assertSame( array( 'lps_local_pickup:7:10', 'lps_local_pickup:7:30' ), array_column( $method->rates, 'id' ) );
	}

	/** @dataProvider availabilitySettings */
	public function test_availability_requires_enabled_method_and_a_matching_active_store( $settings, $disabled, $expected ): void {
		WC_Shipping_Method::$test_instance_settings[7] = $settings;
		foreach ( $disabled as $id ) { $GLOBALS['lps_test_post_meta'][ $id ]['_lps_enabled'] = 'no'; }
		$this->assertSame( $expected, ( new LPS_Shipping_Method( 7 ) )->is_available( array() ) );
	}

	public static function availabilitySettings(): array {
		return array(
			array( array(), array(), true ),
			array( array( 'enabled' => 'no' ), array(), false ),
			array( array( 'locations' => array( '20' ) ), array(), true ),
			array( array( 'locations' => array( '20' ) ), array( 20 ), false ),
			array( array( 'locations' => array( '999' ) ), array(), false ),
			array( array(), array( 10, 20, 30 ), false ),
		);
	}

	public function test_no_stores_means_no_rates_and_unavailable_method(): void {
		$GLOBALS['lps_test_posts'] = array();
		$method = new LPS_Shipping_Method( 7 );
		$method->calculate_shipping();
		$this->assertSame( array(), $method->rates );
		$this->assertFalse( $method->is_available( array() ) );
	}

	private function locationMeta( $address, $price, $tax_status ): array {
		return array(
			'_lps_address'    => $address,
			'_lps_city'       => 'Sofia',
			'_lps_state'      => 'Sofia City',
			'_lps_postcode'   => '1000',
			'_lps_country'    => 'BG',
			'_lps_price'      => $price,
			'_lps_tax_status' => $tax_status,
			'_lps_hours'      => '09:00–18:00',
			'_lps_phone'      => '+359 2 000 0000',
		);
	}
}

