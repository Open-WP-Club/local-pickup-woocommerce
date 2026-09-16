<?php
/**
 * Plugin Name: Local Pickup Stores for WooCommerce
 * Plugin URI:  https://github.com/Open-WP-Club/local-pickup-woocommerce
 * Description: Adds zone-aware store pickup locations with individual prices to the WooCommerce Checkout Block.
 * Version:     1.1.1
 * Author:      Open WP Club contributors
 * Author URI:  https://github.com/Open-WP-Club
 * Text Domain: local-pickup-stores
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 9.0
 * WC tested up to: 11.0
 * License: Apache-2.0
 * License URI: https://www.apache.org/licenses/LICENSE-2.0
 */

defined( 'ABSPATH' ) || exit;

define( 'LPS_VERSION', '1.1.1' );
define( 'LPS_FILE', __FILE__ );
define( 'LPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'LPS_URL', plugin_dir_url( __FILE__ ) );

require_once LPS_PATH . 'includes/lps-plugin.php';

LPS_Plugin::instance();
