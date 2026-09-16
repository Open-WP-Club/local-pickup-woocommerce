<?php

use PHPUnit\Framework\TestCase;

final class PluginLocationDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		LPS_Locations::$locations = array(
			(object) array( 'ID' => 10, 'post_title' => 'Rodina' ),
			(object) array( 'ID' => 20, 'post_title' => 'Free Store' ),
		);

		$GLOBALS['lps_test_post_meta'] = array(
			10 => array( '_lps_price' => '2.00' ),
			20 => array( '_lps_price' => '0' ),
		);
	}

	public function test_priced_location_has_decoded_currency_symbol(): void {
		$locations = LPS_Plugin::instance()->get_pickup_location_data();

		$this->assertSame( "2.00\u{00A0}€", $locations[10]['price'] );
		$this->assertStringNotContainsString( '&nbsp;', $locations[10]['price'] );
		$this->assertStringNotContainsString( '&euro;', $locations[10]['price'] );
	}

	public function test_free_location_uses_free_label(): void {
		$locations = LPS_Plugin::instance()->get_pickup_location_data();

		$this->assertSame( 'Free', $locations[20]['price'] );
	}
}
