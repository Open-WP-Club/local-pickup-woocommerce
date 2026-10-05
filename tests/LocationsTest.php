<?php

final class LocationsTest extends LPS_TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lps_test_posts'] = array( lps_test_location( 10, 'Central' ), lps_test_location( 20, 'West' ) );
		$GLOBALS['lps_test_post_meta'][10] = array( '_lps_address' => '10 Main Street', '_lps_country' => 'BG' );
	}

	public function test_active_locations_exclude_disabled_but_include_legacy_locations(): void {
		$GLOBALS['lps_test_post_meta'][20]['_lps_enabled'] = 'no';
		$this->assertSame( array( 10 ), array_column( LPS_Locations::get_locations(), 'ID' ) );
		$this->assertSame( array( 10, 20 ), array_column( LPS_Locations::get_locations( false ), 'ID' ) );
		$this->assertSame( array(
			'post_type' => 'lps_pickup_location', 'post_status' => 'publish', 'posts_per_page' => -1,
			'orderby' => 'menu_order title', 'order' => 'ASC',
		), $GLOBALS['lps_test_post_query'] );
		$GLOBALS['lps_test_post_meta'][20]['_lps_enabled'] = 'yes';
		$this->assertCount( 2, LPS_Locations::get_locations() );
	}

	public function test_location_lookup_rejects_missing_draft_and_unrelated_posts(): void {
		$this->assertSame( 10, LPS_Locations::get_location( '10' )->ID );
		$this->assertNull( LPS_Locations::get_location( 99 ) );
		get_post( 10 )->post_status = 'draft';
		$this->assertNull( LPS_Locations::get_location( 10 ) );
		get_post( 20 )->post_type = 'page';
		$this->assertNull( LPS_Locations::get_location( 20 ) );
	}

	public function test_address_omits_empty_parts_and_resolves_country_name(): void {
		$this->assertSame( '10 Main Street, Bulgaria', LPS_Locations::get_address( 10 ) );
		$GLOBALS['lps_test_post_meta'][10]['_lps_country'] = 'ZZ';
		$this->assertSame( '10 Main Street', LPS_Locations::get_address( 10 ) );
		$this->assertSame( '', LPS_Locations::get_address( 20 ) );
	}

	/** @dataProvider rejectedSaveRequests */
	public function test_save_requires_nonce_and_edit_permission( $nonce, $denied ): void {
		$_POST = array( 'lps_address' => 'Changed' );
		if ( null !== $nonce ) {
			$_POST['lps_location_nonce'] = $nonce;
		}
		$GLOBALS['lps_test_denied_posts'] = $denied ? array( 10 ) : array();
		$before = $GLOBALS['lps_test_post_meta'];
		LPS_Locations::save( 10 );
		$this->assertSame( $before, $GLOBALS['lps_test_post_meta'] );
		$this->assertSame( array(), WC_Cache_Helper::$invalidations );
	}

	public static function rejectedSaveRequests(): array {
		return array( 'missing nonce' => array( null, false ), 'invalid nonce' => array( 'invalid', false ), 'no permission' => array( 'valid-lps_save_location', true ) );
	}

	public function test_save_persists_details_and_invalidates_shipping_cache(): void {
		$_POST = array(
			'lps_location_nonce' => 'valid-lps_save_location', 'lps_address' => '20 New Street',
			'lps_city' => 'Sofia', 'lps_state' => 'Sofia City', 'lps_postcode' => '1000',
			'lps_phone' => '+359 123', 'lps_hours' => "Monday 09:00–18:00\nSaturday 10:00–14:00",
			'lps_price' => '4.50', 'lps_notify_email' => 'store@example.test',
			'lps_country' => 'de', 'lps_tax_status' => 'none', 'lps_enabled' => 'yes',
		);
		LPS_Locations::save( 10 );
		$expected = array();
		foreach ( array( 'address', 'city', 'state', 'postcode', 'phone', 'hours', 'price', 'notify_email', 'enabled', 'tax_status' ) as $field ) {
			$expected[ '_lps_' . $field ] = $_POST[ 'lps_' . $field ];
		}
		$expected['_lps_country'] = 'DE';
		$this->assertEquals( $expected, $GLOBALS['lps_test_post_meta'][10] );
		$this->assertSame( array( array( 'shipping', true ) ), WC_Cache_Helper::$invalidations );
	}

	public function test_save_defaults_invalid_country_and_tax_status_and_clears_unchecked_enabled(): void {
		$_POST = array( 'lps_location_nonce' => 'valid-lps_save_location', 'lps_country' => 'ZZ', 'lps_tax_status' => 'invalid' );
		LPS_Locations::save( 10 );
		$this->assertSame( 'BG', get_post_meta( 10, '_lps_country', true ) );
		$this->assertSame( 'taxable', get_post_meta( 10, '_lps_tax_status', true ) );
		$this->assertSame( 'no', get_post_meta( 10, '_lps_enabled', true ) );
		$this->assertSame( '', get_post_meta( 10, '_lps_price', true ) );
		$this->assertSame( '', get_post_meta( 10, '_lps_address', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_autosave_does_not_overwrite_location(): void {
		define( 'DOING_AUTOSAVE', true );
		$_POST = array( 'lps_location_nonce' => 'valid-lps_save_location', 'lps_address' => 'Changed' );
		LPS_Locations::save( 10 );
		$this->assertSame( '10 Main Street', get_post_meta( 10, '_lps_address', true ) );
	}

	public function test_publishing_requires_address_from_valid_form_or_existing_metadata(): void {
		$data = array( 'post_type' => LPS_Locations::POST_TYPE, 'post_status' => 'publish' );
		$this->assertSame( 'publish', LPS_Locations::require_address_before_publish( $data, array( 'ID' => 10 ) )['post_status'] );
		$this->assertSame( 'draft', LPS_Locations::require_address_before_publish( $data, array( 'ID' => 20 ) )['post_status'] );
		$_POST = array( 'lps_location_nonce' => 'valid-lps_save_location', 'lps_address' => '  ' );
		$this->assertSame( 'draft', LPS_Locations::require_address_before_publish( $data, array( 'ID' => 10 ) )['post_status'] );
		$_POST['lps_address'] = 'New address';
		$this->assertSame( 'publish', LPS_Locations::require_address_before_publish( $data, array( 'ID' => 20 ) )['post_status'] );
		$_POST['lps_location_nonce'] = 'invalid';
		$this->assertSame( 'draft', LPS_Locations::require_address_before_publish( $data, array( 'ID' => 20 ) )['post_status'] );
		$data['post_type'] = 'page';
		$this->assertSame( $data, LPS_Locations::require_address_before_publish( $data, array( 'ID' => 20 ) ) );
	}

	public function test_duplicate_copies_only_location_metadata_into_a_draft(): void {
		$_GET = array( 'post' => '10', '_wpnonce' => 'valid-lps_duplicate_location_10' );
		$GLOBALS['lps_test_post_meta'][10]['_edit_lock'] = 'do not copy';
		try {
			LPS_Locations::duplicate_location();
			$this->fail( 'Expected redirect to the new draft' );
		} catch ( LPS_Test_Redirect $redirect ) {
			$this->assertSame( admin_url( 'post.php?action=edit&post=1000' ), $redirect->getMessage() );
		}
		$this->assertSame( 'draft', get_post( 1000 )->post_status );
		$this->assertSame( 'Central (copy)', get_post( 1000 )->post_title );
		$this->assertSame( array( '_lps_address' => '10 Main Street', '_lps_country' => 'BG' ), $GLOBALS['lps_test_post_meta'][1000] );
	}

	/** @dataProvider rejectedDuplicateRequests */
	public function test_duplicate_rejects_invalid_requests( $id, $nonce, $denied, $type ): void {
		$_GET = array( 'post' => $id, '_wpnonce' => $nonce );
		$GLOBALS['lps_test_denied_posts'] = $denied ? array( 10 ) : array();
		get_post( 10 )->post_type = $type;
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'invalid' === $nonce ? 'Invalid nonce' : 'not allowed' );
		LPS_Locations::duplicate_location();
	}

	public static function rejectedDuplicateRequests(): array {
		return array(
			array( 10, 'invalid', false, 'lps_pickup_location' ),
			array( 99, 'valid-lps_duplicate_location_99', false, 'lps_pickup_location' ),
			array( 10, 'valid-lps_duplicate_location_10', true, 'lps_pickup_location' ),
			array( 10, 'valid-lps_duplicate_location_10', false, 'page' ),
		);
	}

	public function test_reorder_updates_only_authorized_locations_and_flushes_cache(): void {
		$GLOBALS['lps_test_posts'][] = lps_test_location( 30, 'Other' );
		get_post( 30 )->post_type = 'page';
		$GLOBALS['lps_test_denied_posts'] = array( 20 );
		$_POST = array( 'nonce' => 'valid-lps_reorder_locations', 'ids' => array( '20', '30', '10', '999' ) );
		try {
			LPS_Locations::ajax_reorder();
			$this->fail( 'Expected JSON success' );
		} catch ( LPS_Test_Json_Success $response ) {
			$this->assertSame( 2, get_post( 10 )->menu_order );
		}
		$this->assertSame( 0, get_post( 20 )->menu_order );
		$this->assertSame( 0, get_post( 30 )->menu_order );
		$this->assertSame( array( array( 'shipping', true ) ), WC_Cache_Helper::$invalidations );
	}

	public function test_reorder_rejects_invalid_nonce(): void {
		$_POST = array( 'nonce' => 'invalid', 'ids' => array( 20, 10 ) );
		$this->expectExceptionMessage( 'Invalid nonce' );
		LPS_Locations::ajax_reorder();
	}

	public function test_cache_is_flushed_only_for_location_changes(): void {
		LPS_Locations::maybe_flush_shipping_cache( 999 );
		$this->assertSame( array(), WC_Cache_Helper::$invalidations );
		LPS_Locations::maybe_flush_shipping_cache( 10 );
		$this->assertSame( array( array( 'shipping', true ) ), WC_Cache_Helper::$invalidations );
	}
}
