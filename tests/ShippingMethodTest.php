<?php

use PHPUnit\Framework\TestCase;

final class ShippingMethodTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		LPS_Locations::$locations = array(
			(object) array( 'ID' => 10, 'post_title' => 'Central Store' ),
			(object) array( 'ID' => 20, 'post_title' => 'West Store' ),
			(object) array( 'ID' => 30, 'post_title' => 'East Store' ),
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
		$this->assertSame( '20 West Street', $method->rates[0]['meta_data']['_lps_location_address'] );
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

