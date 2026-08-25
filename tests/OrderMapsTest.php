<?php

use PHPUnit\Framework\TestCase;

final class OrderMapsTest extends TestCase {

	public function test_google_maps_url_uses_encoded_order_address_snapshot(): void {
		$data = $this->displayData();

		$this->assertSame(
			'https://www.google.com/maps/search/?api=1&query=1%20Vitosha%20Blvd%2C%201000%20Sofia%2C%20Bulgaria',
			$data['map_url']
		);
	}

	public function test_frontend_markup_contains_safe_google_maps_link(): void {
		$order = $this->order();

		ob_start();
		LPS_Order::frontend_order_details( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'View in Google Maps</a>', $output );
		$this->assertStringContainsString( 'target="_blank"', $output );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $output );
	}

	public function test_html_email_contains_clickable_google_maps_link(): void {
		ob_start();
		LPS_Order::email_map_link( $this->order(), false, false );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<a href="https://www.google.com/maps/search/?api=1', $output );
		$this->assertStringContainsString( 'View in Google Maps', $output );
	}

	public function test_plain_email_contains_google_maps_url_without_html(): void {
		ob_start();
		LPS_Order::email_map_link( $this->order(), false, true );
		$output = ob_get_clean();

		$this->assertStringStartsWith( 'Google Maps: https://www.google.com/maps/search/?api=1&query=', $output );
		$this->assertStringNotContainsString( '<a ', $output );
	}

	private function displayData(): array {
		$method = new ReflectionMethod( LPS_Order::class, 'get_display_data' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke( null, $this->order() );
	}

	private function order(): WC_Order {
		return new WC_Order(
			array(
				LPS_Order::META_LOCATION_NAME    => 'Central Store',
				LPS_Order::META_LOCATION_ADDRESS => '1 Vitosha Blvd, 1000 Sofia, Bulgaria',
				LPS_Order::META_LOCATION_PHONE   => '+359 2 000 0000',
				LPS_Order::META_LOCATION_HOURS   => '09:00–18:00',
			)
		);
	}
}
