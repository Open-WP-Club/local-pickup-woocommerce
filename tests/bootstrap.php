<?php

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugins/local-pickup-stores/'; }
function get_file_data( $file, $headers ) {
	$data = array();
	foreach ( $headers as $key => $header ) {
		preg_match( '/^ \* ' . preg_quote( $header, '/' ) . ':\s*(.+)$/m', file_get_contents( $file ), $match );
		$data[ $key ] = trim( $match[1] ?? '' );
	}
	return $data;
}

function __( $text, $domain = 'default' ) {
	return $GLOBALS['lps_test_translations'][ $domain ][ $text ] ?? $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( __( $text, $domain ) );
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

function wc_price( $price ) {
	$formatted = number_format( (float) $price, 2 );
	return '<span class="woocommerce-Price-amount amount"><bdi>' . $formatted . '&nbsp;<span class="woocommerce-Price-currency-symbol">&euro;</span></bdi></span>';
}

function wp_strip_all_tags( $string ) {
	return strip_tags( $string );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['lps_test_hooks'][ $hook ][] = $callback;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_action( $hook, $callback, $priority, $accepted_args );
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( '' === $key ) {
		return array_map( function ( $value ) { return array( $value ); }, $GLOBALS['lps_test_post_meta'][ $post_id ] ?? array() );
	}
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
	public $shipping_items = array();

	public function __construct( $meta = array() ) {
		$this->meta = $meta;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function get_shipping_methods() {
		return $this->shipping_items;
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

	public function is_available( $package ) {
		return 'yes' === $this->enabled;
	}

	public function process_admin_options() {
		return true;
	}
}

// Small WordPress/WooCommerce doubles: plugin classes themselves are loaded below.
function lps_test_location( $id, $title, $status = 'publish' ) {
	return (object) array( 'ID' => $id, 'post_title' => $title, 'post_type' => 'lps_pickup_location', 'post_status' => $status, 'menu_order' => 0 );
}

function get_posts( $args ) {
	$GLOBALS['lps_test_post_query'] = $args;
	return $GLOBALS['lps_test_posts'];
}

function get_post( $id ) {
	foreach ( $GLOBALS['lps_test_posts'] as $post ) {
		if ( $post->ID === (int) $id ) {
			return $post;
		}
	}
	return null;
}

function get_post_type( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_type : false;
}

function update_post_meta( $id, $key, $value ) {
	$GLOBALS['lps_test_post_meta'][ $id ][ $key ] = $value;
}

function wp_update_post( $data ) {
	$post = get_post( $data['ID'] );
	foreach ( $data as $key => $value ) {
		$post->$key = $value;
	}
	return $post->ID;
}

function wp_insert_post( $data, $wp_error = false ) {
	$id = 1000;
	$GLOBALS['lps_test_posts'][] = (object) ( array( 'ID' => $id ) + $data );
	return $id;
}

function is_wp_error( $value ) { return false; }
function maybe_unserialize( $value ) { return $value; }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wc_format_decimal( $value ) { return str_replace( ',', '.', $value ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid-' . $action === $nonce; }
function current_user_can( $capability, $id ) { return ! in_array( $id, $GLOBALS['lps_test_denied_posts'], true ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }

function check_admin_referer( $action ) {
	if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', $action ) ) {
		wp_die( 'Invalid nonce' );
	}
}

function check_ajax_referer( $action, $key ) {
	if ( ! wp_verify_nonce( $_POST[ $key ] ?? '', $action ) ) {
		wp_die( 'Invalid nonce' );
	}
}

// These WP functions terminate the request. Exceptions let tests inspect its outcome.
class LPS_Test_Redirect extends RuntimeException {}
class LPS_Test_Json_Success extends RuntimeException {}
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_safe_redirect( $url ) { throw new LPS_Test_Redirect( $url ); }
function wp_send_json_success() { throw new LPS_Test_Json_Success(); }

class WC_Cache_Helper {
	public static $invalidations = array();
	public static function get_transient_version( $group, $refresh = false ) {
		self::$invalidations[] = array( $group, $refresh );
	}
}

class WC_Shipping_Zones {
	public static $zones = array();
	public static function get_zones() { return self::$zones; }
}

class WC_Shipping_Zone {
	public static $methods = array();
	private $id;
	public function __construct( $id ) { $this->id = $id; }
	public function get_shipping_methods() { return self::$methods[ $this->id ] ?? array(); }
}

function is_checkout() { return $GLOBALS['lps_test_is_checkout']; }
function is_order_received_page() { return $GLOBALS['lps_test_order_received']; }
function wp_enqueue_style( ...$args ) { $GLOBALS['lps_test_styles'][] = $args; }
function wp_enqueue_script( ...$args ) { $GLOBALS['lps_test_scripts'][] = $args; }
function wp_localize_script( ...$args ) { $GLOBALS['lps_test_localized'][] = $args; }

abstract class LPS_TestCase extends PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
		$_GET = array();
		foreach ( array( 'posts', 'post_meta', 'post_query', 'denied_posts', 'hooks', 'styles', 'scripts', 'localized', 'translations' ) as $key ) {
			$GLOBALS[ 'lps_test_' . $key ] = array();
		}
		$GLOBALS['lps_test_is_checkout'] = true;
		$GLOBALS['lps_test_order_received'] = false;
		$GLOBALS['lps_test_woocommerce'] = (object) array(
			'session' => null,
			'countries' => new class {
				public function get_countries() { return array( 'BG' => 'Bulgaria', 'DE' => 'Germany' ); }
				public function get_base_country() { return 'BG'; }
			},
		);
		WC_Shipping_Method::$test_instance_settings = array();
		WC_Shipping_Zones::$zones = array();
		WC_Shipping_Zone::$methods = array();
		WC_Cache_Helper::$invalidations = array();
	}
}

require_once dirname( __DIR__ ) . '/includes/lps-locations.php';
require_once dirname( __DIR__ ) . '/includes/lps-shipping-method.php';
require_once dirname( __DIR__ ) . '/includes/lps-order.php';
require_once dirname( __DIR__ ) . '/includes/lps-plugin.php';
require_once dirname( __DIR__ ) . '/local-pickup-stores.php';
