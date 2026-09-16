<?php

defined( 'ABSPATH' ) || exit;

final class LPS_Plugin {

	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	public function declare_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LPS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LPS_FILE, true );
		}
	}

	public function load() {
		load_plugin_textdomain( 'local-pickup-stores', false, dirname( plugin_basename( LPS_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		require_once LPS_PATH . 'includes/lps-locations.php';
		require_once LPS_PATH . 'includes/lps-shipping-method.php';
		require_once LPS_PATH . 'includes/lps-order.php';

		LPS_Locations::init();
		LPS_Order::init();

		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );
		add_filter( 'woocommerce_local_pickup_methods', array( $this, 'register_as_local_pickup' ) );
		add_filter( 'option_woocommerce_pickup_location_settings', array( $this, 'enable_block_pickup_ui' ) );
		add_filter( 'default_option_woocommerce_pickup_location_settings', array( $this, 'enable_block_pickup_ui' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LPS_FILE ), array( $this, 'plugin_action_links' ) );
	}

	public function register_shipping_method( $methods ) {
		$methods['lps_local_pickup'] = 'LPS_Shipping_Method';
		return $methods;
	}

	public function register_as_local_pickup( $method_ids ) {
		$method_ids[] = 'lps_local_pickup';
		return array_unique( $method_ids );
	}

	public function enable_block_pickup_ui( $settings ) {
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = 'yes';
		return $settings;
	}

	public function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_style(
			'lps-checkout',
			LPS_URL . 'assets/css/checkout.css',
			array(),
			LPS_VERSION
		);

		wp_enqueue_script(
			'lps-checkout',
			LPS_URL . 'assets/js/checkout.js',
			array(),
			LPS_VERSION,
			true
		);

		$method_settings = $this->get_pickup_method_settings();

		wp_localize_script(
			'lps-checkout',
			'lpsCheckout',
			array(
				'pickupLocation' => __( 'Pickup location', 'local-pickup-stores' ),
				'selectLocation' => __( 'Select a pickup location', 'local-pickup-stores' ),
				'methodTitles'   => $method_settings['titles'],
				'showPrice'      => $method_settings['show_price'],
				'locations'      => $this->get_pickup_location_data(),
			)
		);
	}

	public function get_pickup_method_settings() {
		$titles     = array();
		$show_price = array();
		$zones      = WC_Shipping_Zones::get_zones();
		$zones[]    = array( 'zone_id' => 0 );

		foreach ( $zones as $zone_data ) {
			$zone = new WC_Shipping_Zone( $zone_data['zone_id'] );
			foreach ( $zone->get_shipping_methods() as $method ) {
				if ( 'lps_local_pickup' === $method->id ) {
					$titles[ $method->instance_id ]     = $method->title;
					$show_price[ $method->instance_id ] = 'yes' === $method->get_option( 'show_price', 'yes' );
				}
			}
		}

		return array(
			'titles'     => $titles,
			'show_price' => $show_price,
		);
	}

	public function get_pickup_location_data() {
		$locations = array();

		foreach ( LPS_Locations::get_locations( false ) as $location ) {
			$price                      = (float) get_post_meta( $location->ID, '_lps_price', true );
			$locations[ $location->ID ] = array(
				'name'  => $location->post_title,
				'price' => $price > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES ) : __( 'Free', 'local-pickup-stores' ),
			);
		}

		return $locations;
	}

	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'edit.php?post_type=lps_pickup_location' ) ) . '">' .
			esc_html__( 'Pickup locations', 'local-pickup-stores' ) . '</a>'
		);

		return $links;
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Local Pickup Stores requires WooCommerce to be installed and active.', 'local-pickup-stores' );
		echo '</p></div>';
	}
}
