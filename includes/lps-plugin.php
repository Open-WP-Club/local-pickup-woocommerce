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

		wp_localize_script(
			'lps-checkout',
			'lpsCheckout',
			array(
				'pickupLocation' => __( 'Pickup location', 'local-pickup-stores' ),
				'selectLocation' => __( 'Select a pickup location', 'local-pickup-stores' ),
			)
		);
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
