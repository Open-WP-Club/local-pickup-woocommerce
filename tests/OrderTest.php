<?php

final class OrderTest extends LPS_TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lps_test_posts'] = array( lps_test_location( 10, 'Central Store' ) );
		$GLOBALS['lps_test_post_meta'][10] = array(
			'_lps_address' => '10 Main Street', '_lps_city' => 'Sofia', '_lps_state' => 'Sofia City',
			'_lps_country' => 'BG', '_lps_postcode' => '1000', '_lps_price' => '2.00',
			'_lps_hours' => '09:00–18:00', '_lps_phone' => '+359 123', '_lps_notify_email' => 'store@example.test',
		);
	}

	private function pickupOrder( $method_id = 'lps_local_pickup', $overrides = array() ): WC_Order {
		$method = new LPS_Shipping_Method( 7 );
		$method->calculate_shipping();
		$meta = array_replace( $method->rates[0]['meta_data'], $overrides );
		$order = new WC_Order();
		$order->shipping_items[] = new class( $method_id, $meta ) {
			private $method_id;
			private $meta;
			public function __construct( $method_id, $meta ) { $this->method_id = $method_id; $this->meta = $meta; }
			public function get_method_id() { return $this->method_id; }
			public function get_instance_id() { return 7; }
			public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
		};
		return $order;
	}

	public function test_valid_pickup_saves_the_shipping_rate_snapshot(): void {
		$order = $this->pickupOrder();
		get_post( 10 )->post_title = 'Renamed store';
		$GLOBALS['lps_test_post_meta'][10]['_lps_address'] = 'Changed address';
		LPS_Order::validate_and_save( $order );
		$this->assertSame( 10, $order->get_meta( LPS_Order::META_LOCATION_ID ) );
		$this->assertSame( 'Central Store', $order->get_meta( LPS_Order::META_LOCATION_NAME ) );
		$this->assertSame( '10 Main Street, 1000, Sofia, Sofia City, Bulgaria', $order->get_meta( LPS_Order::META_LOCATION_ADDRESS ) );
		$this->assertSame( '09:00–18:00', $order->get_meta( LPS_Order::META_LOCATION_HOURS ) );
		$this->assertSame( '+359 123', $order->get_meta( LPS_Order::META_LOCATION_PHONE ) );
	}

	public function test_missing_snapshot_name_and_address_fall_back_to_location(): void {
		$order = $this->pickupOrder( 'lps_local_pickup', array( '_lps_location_name' => '', '_lps_location_address' => '' ) );
		LPS_Order::validate_and_save( $order );
		$this->assertSame( 'Central Store', $order->get_meta( LPS_Order::META_LOCATION_NAME ) );
		$this->assertSame( LPS_Locations::get_address( 10 ), $order->get_meta( LPS_Order::META_LOCATION_ADDRESS ) );
	}

	public function test_non_pickup_orders_are_not_given_pickup_metadata(): void {
		foreach ( array( new WC_Order(), $this->pickupOrder( 'flat_rate' ) ) as $order ) {
			LPS_Order::validate_and_save( $order );
			$this->assertSame( '', $order->get_meta( LPS_Order::META_LOCATION_ID ) );
		}
	}

	/** @dataProvider invalidLocations */
	public function test_invalid_pickup_is_rejected_before_order_metadata_is_saved( $reason ): void {
		$order = $this->pickupOrder();
		if ( 'deleted' === $reason ) { $GLOBALS['lps_test_posts'] = array(); }
		if ( 'draft' === $reason ) { get_post( 10 )->post_status = 'draft'; }
		if ( 'disabled' === $reason ) { $GLOBALS['lps_test_post_meta'][10]['_lps_enabled'] = 'no'; }
		if ( 'wrong zone' === $reason ) { WC_Shipping_Method::$test_instance_settings[7] = array( 'locations' => array( '20' ) ); }
		if ( 'missing id' === $reason ) { $order = $this->pickupOrder( 'lps_local_pickup', array( '_lps_location_id' => '' ) ); }
		try {
			LPS_Order::validate_and_save( $order );
			$this->fail( 'Invalid pickup was accepted' );
		} catch ( Exception $error ) {
			$this->assertSame( 'Please select a valid pickup location before placing your order.', $error->getMessage() );
		}
		$this->assertSame( '', $order->get_meta( LPS_Order::META_LOCATION_ID ) );
	}

	public static function invalidLocations(): array {
		return array( array( 'deleted' ), array( 'draft' ), array( 'disabled' ), array( 'wrong zone' ), array( 'missing id' ) );
	}

	public function test_allowed_location_ids_can_be_strings_in_zone_settings(): void {
		$order = $this->pickupOrder();
		WC_Shipping_Method::$test_instance_settings[7] = array( 'locations' => array( '10' ) );
		LPS_Order::validate_and_save( $order );
		$this->assertSame( 10, $order->get_meta( LPS_Order::META_LOCATION_ID ) );
	}

	/** @dataProvider taxSelections */
	public function test_tax_address_changes_only_for_an_existing_selected_pickup( $selection, $pickup ): void {
		$GLOBALS['lps_test_woocommerce']->session = new class( $selection ) {
			private $selection;
			public function __construct( $selection ) { $this->selection = $selection; }
			public function get( $key, $default ) { return $this->selection; }
		};
		$original = array( 'DE', '', '10115', 'Berlin' );
		$this->assertSame( $pickup ? array( 'BG', 'Sofia City', '1000', 'Sofia' ) : $original, LPS_Order::pickup_taxable_address( $original ) );
	}

	public static function taxSelections(): array {
		return array(
			array( array( 'lps_local_pickup:7:10' ), true ), array( array( 'flat_rate:7' ), false ),
			array( array( 'lps_local_pickup:7:999' ), false ), array( array( 'lps_local_pickup:7' ), false ),
			array( array(), false ), array( 'malformed', false ), array( null, false ),
		);
	}

	public function test_tax_address_is_unchanged_without_a_session(): void {
		$this->assertSame( array( 'BG' ), LPS_Order::pickup_taxable_address( array( 'BG' ) ) );
	}

	/** @dataProvider emailRecipients */
	public function test_notification_recipient_preserves_admin_and_avoids_duplicates( $email, $recipients, $expected ): void {
		$order = new WC_Order( array( LPS_Order::META_LOCATION_ID => 10 ) );
		$GLOBALS['lps_test_post_meta'][10]['_lps_notify_email'] = $email;
		$this->assertSame( $expected, LPS_Order::add_location_notify_recipient( $recipients, $order ) );
	}

	public static function emailRecipients(): array {
		return array(
			array( 'store@example.test', 'admin@example.test', 'admin@example.test, store@example.test' ),
			array( 'store@example.test', 'admin@example.test, store@example.test', 'admin@example.test, store@example.test' ),
			array( 'store@example.test', '', 'store@example.test' ),
			array( '', 'admin@example.test', 'admin@example.test' ),
			array( 'invalid', 'admin@example.test', 'admin@example.test' ),
		);
	}

	public function test_non_pickup_and_missing_orders_leave_recipients_unchanged(): void {
		$this->assertSame( 'admin@example.test', LPS_Order::add_location_notify_recipient( 'admin@example.test', null ) );
		$this->assertSame( 'admin@example.test', LPS_Order::add_location_notify_recipient( 'admin@example.test', new WC_Order() ) );
	}

	public function test_email_fields_include_saved_details_and_preserve_other_fields(): void {
		$order = $this->pickupOrder();
		LPS_Order::validate_and_save( $order );
		$fields = LPS_Order::email_order_meta( array( 'existing' => 'keep' ), false, $order );
		$this->assertSame( 'keep', $fields['existing'] );
		$this->assertSame( 'Pickup location', $fields['lps_pickup_location']['label'] );
		$this->assertSame( 'Central Store — 10 Main Street, 1000, Sofia, Sofia City, Bulgaria — +359 123 — 09:00–18:00', $fields['lps_pickup_location']['value'] );
		$this->assertSame( array( 'existing' => 'keep' ), LPS_Order::email_order_meta( array( 'existing' => 'keep' ), false, new WC_Order() ) );
	}

	public function test_order_details_escape_location_content(): void {
		$order = new WC_Order( array(
			LPS_Order::META_LOCATION_NAME => '<script>alert(1)</script>',
			LPS_Order::META_LOCATION_ADDRESS => 'A & B',
			LPS_Order::META_LOCATION_PHONE => '<b>123</b>',
			LPS_Order::META_LOCATION_HOURS => "Monday\nTuesday",
		) );
		foreach ( array( 'admin_order_details', 'frontend_order_details' ) as $method ) {
			ob_start();
			LPS_Order::$method( $order );
			$html = ob_get_clean();
			$this->assertStringNotContainsString( '<script>', $html );
			$this->assertStringContainsString( '&lt;script&gt;', $html );
			$this->assertStringContainsString( 'A &amp; B', $html );
			$this->assertStringContainsString( '&lt;b&gt;123&lt;/b&gt;', $html );
			$this->assertStringContainsString( 'Monday<br />', $html );
		}
	}

	public function test_no_pickup_order_outputs_no_pickup_details_or_map_links(): void {
		$this->expectOutputString( '' );
		LPS_Order::admin_order_details( new WC_Order() );
		LPS_Order::frontend_order_details( new WC_Order() );
		LPS_Order::email_map_link( new WC_Order(), false, false );
		LPS_Order::email_map_link( new WC_Order( array( LPS_Order::META_LOCATION_NAME => 'Store without address' ) ), false, true );
	}
}
