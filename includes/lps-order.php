<?php

defined( 'ABSPATH' ) || exit;

final class LPS_Order {

	const META_LOCATION_ID      = '_lps_pickup_location_id';
	const META_LOCATION_NAME    = '_lps_pickup_location_name';
	const META_LOCATION_ADDRESS = '_lps_pickup_location_address';
	const META_LOCATION_HOURS   = '_lps_pickup_location_hours';
	const META_LOCATION_PHONE   = '_lps_pickup_location_phone';

	public static function init() {
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'validate_and_save' ) );
		add_filter( 'woocommerce_customer_taxable_address', array( __CLASS__, 'pickup_taxable_address' ), 20 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_order_details' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'email_order_meta' ), 10, 3 );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email_map_link' ), 20, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'frontend_order_details' ) );
		add_filter( 'woocommerce_email_recipient_new_order', array( __CLASS__, 'add_location_notify_recipient' ), 10, 2 );
	}

	public static function add_location_notify_recipient( $recipient, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $recipient;
		}

		$location_id = absint( $order->get_meta( self::META_LOCATION_ID, true ) );
		if ( ! $location_id ) {
			return $recipient;
		}

		$notify_email = get_post_meta( $location_id, '_lps_notify_email', true );
		if ( ! $notify_email || ! is_email( $notify_email ) ) {
			return $recipient;
		}

		$recipients = array_filter( array_map( 'trim', explode( ',', (string) $recipient ) ) );
		if ( in_array( $notify_email, $recipients, true ) ) {
			return $recipient;
		}

		$recipients[] = $notify_email;
		return implode( ', ', $recipients );
	}

	public static function pickup_taxable_address( $address ) {
		if ( ! WC()->session ) {
			return $address;
		}

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		$chosen_method  = is_array( $chosen_methods ) ? current( $chosen_methods ) : '';
		$parts          = is_string( $chosen_method ) ? explode( ':', $chosen_method ) : array();

		if ( 'lps_local_pickup' !== ( $parts[0] ?? '' ) || empty( $parts[2] ) ) {
			return $address;
		}

		$location = LPS_Locations::get_location( absint( $parts[2] ) );
		if ( ! $location ) {
			return $address;
		}

		return array(
			get_post_meta( $location->ID, '_lps_country', true ),
			get_post_meta( $location->ID, '_lps_state', true ),
			get_post_meta( $location->ID, '_lps_postcode', true ),
			get_post_meta( $location->ID, '_lps_city', true ),
		);
	}

	public static function validate_and_save( $order ) {
		$pickup_item = null;
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if ( 'lps_local_pickup' === $shipping_item->get_method_id() ) {
				$pickup_item = $shipping_item;
				break;
			}
		}

		if ( ! $pickup_item ) {
			return;
		}

		$location_id = absint( $pickup_item->get_meta( '_lps_location_id', true ) );
		$location    = LPS_Locations::get_location( $location_id );
		$enabled     = $location ? get_post_meta( $location_id, '_lps_enabled', true ) : 'no';

		if ( ! $location || 'no' === $enabled || ! self::is_allowed_for_instance( $location_id, $pickup_item->get_instance_id() ) ) {
			self::throw_checkout_error();
		}

		$order->update_meta_data( self::META_LOCATION_ID, $location_id );
		$location_name    = $pickup_item->get_meta( '_lps_location_name', true );
		$location_address = $pickup_item->get_meta( '_lps_location_address', true );
		$order->update_meta_data( self::META_LOCATION_NAME, $location_name ? $location_name : $location->post_title );
		$order->update_meta_data( self::META_LOCATION_ADDRESS, $location_address ? $location_address : LPS_Locations::get_address( $location_id ) );
		$order->update_meta_data( self::META_LOCATION_HOURS, $pickup_item->get_meta( '_lps_location_hours', true ) );
		$order->update_meta_data( self::META_LOCATION_PHONE, $pickup_item->get_meta( '_lps_location_phone', true ) );
	}

	private static function is_allowed_for_instance( $location_id, $instance_id ) {
		$method  = new LPS_Shipping_Method( $instance_id );
		$allowed = array_filter( array_map( 'absint', (array) $method->get_option( 'locations', array() ) ) );
		return empty( $allowed ) || in_array( $location_id, $allowed, true );
	}

	private static function throw_checkout_error() {
		$message = __( 'Please select a valid pickup location before placing your order.', 'local-pickup-stores' );

		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lps_invalid_pickup_location', $message, 400 );
		}

		throw new Exception( $message );
	}

	public static function admin_order_details( $order ) {
		$data = self::get_display_data( $order );
		if ( ! $data ) {
			return;
		}

		echo '<div class="lps-order-pickup"><h3>' . esc_html__( 'Pickup location', 'local-pickup-stores' ) . '</h3>';
		echo wp_kses_post( self::format_data( $data ) );
		echo '</div>';
	}

	public static function email_order_meta( $fields, $sent_to_admin, $order ) {
		$data = self::get_display_data( $order );
		if ( $data ) {
			$fields['lps_pickup_location'] = array(
				'label' => __( 'Pickup location', 'local-pickup-stores' ),
				'value' => implode( ' — ', array_filter( array( $data['name'], $data['address'], $data['phone'], $data['hours'] ) ) ),
			);
		}

		return $fields;
	}

	public static function email_map_link( $order, $sent_to_admin, $plain_text ) {
		$data = self::get_display_data( $order );
		if ( ! $data || ! $data['map_url'] ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html__( 'Google Maps:', 'local-pickup-stores' ) . ' ' . esc_url_raw( $data['map_url'] ) . "\n";
			return;
		}

		echo '<p><a href="' . esc_url( $data['map_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View in Google Maps', 'local-pickup-stores' ) . '</a></p>';
	}

	public static function frontend_order_details( $order ) {
		$data = self::get_display_data( $order );
		if ( ! $data ) {
			return;
		}

		echo '<section class="woocommerce-order-details lps-order-pickup">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Pickup location', 'local-pickup-stores' ) . '</h2>';
		echo wp_kses_post( self::format_data( $data ) );
		echo '</section>';
	}

	private static function get_display_data( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return null;
		}

		$name = $order->get_meta( self::META_LOCATION_NAME, true );
		if ( ! $name ) {
			return null;
		}

		$address = $order->get_meta( self::META_LOCATION_ADDRESS, true );

		return array(
			'name'    => $name,
			'address' => $address,
			'phone'   => $order->get_meta( self::META_LOCATION_PHONE, true ),
			'hours'   => $order->get_meta( self::META_LOCATION_HOURS, true ),
			'map_url' => self::get_map_url( $address ),
		);
	}

	private static function get_map_url( $address ) {
		if ( ! $address ) {
			return '';
		}

		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
	}

	private static function format_data( $data ) {
		$html = '<p><strong>' . esc_html( $data['name'] ) . '</strong>';
		if ( $data['address'] ) {
			$html .= '<br>' . esc_html( $data['address'] );
		}
		if ( $data['map_url'] ) {
			$html .= '<br><a href="' . esc_url( $data['map_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View in Google Maps', 'local-pickup-stores' ) . '</a>';
		}
		if ( $data['phone'] ) {
			$html .= '<br><span>' . esc_html__( 'Phone:', 'local-pickup-stores' ) . ' ' . esc_html( $data['phone'] ) . '</span>';
		}
		if ( $data['hours'] ) {
			$html .= '<br><span>' . esc_html__( 'Opening hours:', 'local-pickup-stores' ) . ' ' . nl2br( esc_html( $data['hours'] ) ) . '</span>';
		}
		return $html . '</p>';
	}
}
