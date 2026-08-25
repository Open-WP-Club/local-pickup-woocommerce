<?php

defined( 'ABSPATH' ) || exit;

final class LPS_Locations {

	const POST_TYPE = 'lps_pickup_location';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ) );
		add_action( 'trashed_post', array( __CLASS__, 'maybe_flush_shipping_cache' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_flush_shipping_cache' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	public static function maybe_flush_shipping_cache( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) && class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Pickup locations', 'local-pickup-stores' ),
					'singular_name' => __( 'Pickup location', 'local-pickup-stores' ),
					'add_new_item'  => __( 'Add pickup location', 'local-pickup-stores' ),
					'edit_item'     => __( 'Edit pickup location', 'local-pickup-stores' ),
					'menu_name'     => __( 'Pickup locations', 'local-pickup-stores' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'menu_icon'           => 'dashicons-location-alt',
				'capability_type'     => 'product',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
			)
		);
	}

	public static function add_meta_box() {
		add_meta_box(
			'lps-location-details',
			__( 'Location details', 'local-pickup-stores' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'lps_save_location', 'lps_location_nonce' );

		$fields = array(
			'address'  => array( __( 'Street address', 'local-pickup-stores' ), 'text' ),
			'city'     => array( __( 'City', 'local-pickup-stores' ), 'text' ),
			'state'    => array( __( 'State / County', 'local-pickup-stores' ), 'text' ),
			'postcode' => array( __( 'Postcode', 'local-pickup-stores' ), 'text' ),
			'phone'    => array( __( 'Phone', 'local-pickup-stores' ), 'text' ),
			'hours'    => array( __( 'Opening hours', 'local-pickup-stores' ), 'textarea' ),
			'price'    => array( __( 'Pickup price', 'local-pickup-stores' ), 'number' ),
		);

		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $field ) {
			$value = get_post_meta( $post->ID, '_lps_' . $key, true );
			echo '<tr><th scope="row"><label for="lps_' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
			if ( 'textarea' === $field[1] ) {
				echo '<textarea class="large-text" rows="3" id="lps_' . esc_attr( $key ) . '" name="lps_' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
			} else {
				$attributes = 'number' === $field[1] ? ' type="number" min="0" step="0.01"' : ' type="text"';
				echo '<input class="regular-text"' . $attributes . ' id="lps_' . esc_attr( $key ) . '" name="lps_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 'price' === $key ) {
				echo '<p class="description">' . esc_html__( 'Leave empty or enter 0 for free pickup. The store currency is used.', 'local-pickup-stores' ) . '</p>';
			}
			echo '</td></tr>';
		}

		$country    = get_post_meta( $post->ID, '_lps_country', true );
		$country    = $country ? $country : WC()->countries->get_base_country();
		$tax_status = get_post_meta( $post->ID, '_lps_tax_status', true );
		$enabled    = get_post_meta( $post->ID, '_lps_enabled', true );
		$enabled    = '' === $enabled ? 'yes' : $enabled;

		echo '<tr><th scope="row"><label for="lps_country">' . esc_html__( 'Country / Region', 'local-pickup-stores' ) . '</label></th><td>';
		echo '<select class="wc-enhanced-select" id="lps_country" name="lps_country" style="width: 350px">';
		foreach ( WC()->countries->get_countries() as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $country, $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="lps_tax_status">' . esc_html__( 'Tax status', 'local-pickup-stores' ) . '</label></th><td>';
		echo '<select id="lps_tax_status" name="lps_tax_status">';
		echo '<option value="taxable"' . selected( $tax_status, 'taxable', false ) . '>' . esc_html__( 'Taxable', 'local-pickup-stores' ) . '</option>';
		echo '<option value="none"' . selected( $tax_status, 'none', false ) . '>' . esc_html__( 'None', 'local-pickup-stores' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'local-pickup-stores' ) . '</th><td>';
		echo '<label><input type="checkbox" name="lps_enabled" value="yes"' . checked( $enabled, 'yes', false ) . '> ' . esc_html__( 'Enable this pickup location', 'local-pickup-stores' ) . '</label>';
		echo '</td></tr>';
		echo '</tbody></table>';
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['lps_location_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lps_location_nonce'] ) ), 'lps_save_location' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array( 'address', 'city', 'state', 'postcode', 'phone' );
		foreach ( $text_fields as $field ) {
			$value = isset( $_POST[ 'lps_' . $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'lps_' . $field ] ) ) : '';
			update_post_meta( $post_id, '_lps_' . $field, $value );
		}

		$hours = isset( $_POST['lps_hours'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lps_hours'] ) ) : '';
		$price = isset( $_POST['lps_price'] ) ? wc_format_decimal( wp_unslash( $_POST['lps_price'] ) ) : '';
		update_post_meta( $post_id, '_lps_hours', $hours );
		update_post_meta( $post_id, '_lps_price', $price );
		update_post_meta( $post_id, '_lps_enabled', isset( $_POST['lps_enabled'] ) ? 'yes' : 'no' );

		$country = isset( $_POST['lps_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['lps_country'] ) ) ) : '';
		if ( ! isset( WC()->countries->get_countries()[ $country ] ) ) {
			$country = WC()->countries->get_base_country();
		}
		update_post_meta( $post_id, '_lps_country', $country );

		$posted_tax_status = isset( $_POST['lps_tax_status'] ) ? sanitize_text_field( wp_unslash( $_POST['lps_tax_status'] ) ) : '';
		$tax_status        = 'none' === $posted_tax_status ? 'none' : 'taxable';
		update_post_meta( $post_id, '_lps_tax_status', $tax_status );

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	public static function get_locations( $active_only = true ) {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		if ( ! $active_only ) {
			return $posts;
		}

		return array_values(
			array_filter(
				$posts,
				function ( $post ) {
					$enabled = get_post_meta( $post->ID, '_lps_enabled', true );
					return '' === $enabled || 'yes' === $enabled;
				}
			)
		);
	}

	public static function get_location( $location_id ) {
		$post = get_post( absint( $location_id ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return $post;
	}

	public static function get_address( $location_id ) {
		$parts = array_filter(
			array(
				get_post_meta( $location_id, '_lps_address', true ),
				get_post_meta( $location_id, '_lps_postcode', true ),
				get_post_meta( $location_id, '_lps_city', true ),
				get_post_meta( $location_id, '_lps_state', true ),
			)
		);

		$country_code = get_post_meta( $location_id, '_lps_country', true );
		$countries    = WC()->countries->get_countries();
		if ( $country_code && isset( $countries[ $country_code ] ) ) {
			$parts[] = $countries[ $country_code ];
		}

		return implode( ', ', $parts );
	}

	public static function columns( $columns ) {
		return array(
			'cb'      => $columns['cb'],
			'title'   => __( 'Location', 'local-pickup-stores' ),
			'address' => __( 'Address', 'local-pickup-stores' ),
			'price'   => __( 'Price', 'local-pickup-stores' ),
			'status'  => __( 'Status', 'local-pickup-stores' ),
			'date'    => $columns['date'],
		);
	}

	public static function column_content( $column, $post_id ) {
		if ( 'address' === $column ) {
			echo esc_html( self::get_address( $post_id ) );
		} elseif ( 'price' === $column ) {
			$price = (float) get_post_meta( $post_id, '_lps_price', true );
			echo $price > 0 ? wp_kses_post( wc_price( $price ) ) : esc_html__( 'Free', 'local-pickup-stores' );
		} elseif ( 'status' === $column ) {
			$enabled = get_post_meta( $post_id, '_lps_enabled', true );
			echo ( '' === $enabled || 'yes' === $enabled ) ? esc_html__( 'Enabled', 'local-pickup-stores' ) : esc_html__( 'Disabled', 'local-pickup-stores' );
		}
	}
}
