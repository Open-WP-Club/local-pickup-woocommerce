<?php

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

function wp_kses_post( $html ) {
	return $html;
}

function add_action() {
	return true;
}

function add_filter() {
	return true;
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_post_meta( $post_id, $key, $single = false ) {
	$value = $GLOBALS['lps_test_post_meta'][ $post_id ][ $key ] ?? '';
	return $single ? $value : array( $value );
}

function wc_get_order( $order ) {
	return $order;
}

function WC() {
	return $GLOBALS['lps_test_woocommerce'] ?? null;
}

class WC_Order {
	private $meta = array();

	public function __construct( $meta = array() ) {
		$this->meta = $meta;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
}

class WC_Shipping_Method {
	public static $test_instance_settings = array();

	public $id = '';
	public $instance_id = 0;
	public $method_title = '';
	public $method_description = '';
	public $supports = array();
	public $title = '';
	public $enabled = 'yes';
	public $instance_form_fields = array();
	public $form_fields = array();
	public $instance_settings = array();
	public $rates = array();
	public $tax_status = 'taxable';

	public function __construct( $instance_id = 0 ) {
		$this->instance_id = (int) $instance_id;
	}

	public function init_instance_settings() {
		$this->instance_settings = self::$test_instance_settings[ $this->instance_id ] ?? array();
	}

	public function get_option( $key, $empty_value = null ) {
		if ( array_key_exists( $key, $this->instance_settings ) ) {
			return $this->instance_settings[ $key ];
		}

		if ( isset( $this->instance_form_fields[ $key ]['default'] ) ) {
			return $this->instance_form_fields[ $key ]['default'];
		}

		return $empty_value;
	}

	public function get_rate_id() {
		return $this->id . ':' . $this->instance_id;
	}

	public function add_rate( $rate ) {
		$rate['tax_status'] = $this->tax_status;
		$this->rates[]      = $rate;
	}

	public function process_admin_options() {
		return true;
	}
}

final class LPS_Locations {
	public static $locations = array();

	public static function get_locations( $active_only = true ) {
		return self::$locations;
	}

	public static function get_location( $location_id ) {
		foreach ( self::$locations as $location ) {
			if ( $location->ID === (int) $location_id ) {
				return $location;
			}
		}

		return null;
	}

	public static function get_address( $location_id ) {
		return get_post_meta( $location_id, '_lps_address', true );
	}
}

require_once dirname( __DIR__ ) . '/includes/lps-shipping-method.php';
require_once dirname( __DIR__ ) . '/includes/lps-order.php';
