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
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_settings_tab' ), 21 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_settings_tab' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_default_order' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_reorder_script' ) );
		add_action( 'wp_ajax_lps_reorder_locations', array( __CLASS__, 'ajax_reorder' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'require_address_before_publish' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'missing_address_notice' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_duplicate_row_action' ), 10, 2 );
		add_action( 'admin_action_lps_duplicate_location', array( __CLASS__, 'duplicate_location' ) );
	}

	public static function add_duplicate_row_action( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'lps_duplicate_location',
					'post'   => $post->ID,
				),
				admin_url( 'admin.php' )
			),
			'lps_duplicate_location_' . $post->ID
		);

		$actions['duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'local-pickup-stores' ) . '</a>';
		return $actions;
	}

	public static function duplicate_location() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'lps_duplicate_location_' . $post_id );

		$original = get_post( $post_id );
		if ( ! $original || self::POST_TYPE !== $original->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this pickup location.', 'local-pickup-stores' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'draft',
				/* translators: %s: original pickup location title. */
				'post_title'  => sprintf( __( '%s (copy)', 'local-pickup-stores' ), $original->post_title ),
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 === strpos( $key, '_lps_' ) ) {
				update_post_meta( $new_id, $key, maybe_unserialize( $values[0] ) );
			}
		}

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
		exit;
	}

	public static function admin_default_order( $query ) {
		global $pagenow, $typenow;
		if ( is_admin() && $query->is_main_query() && 'edit.php' === $pagenow && self::POST_TYPE === $typenow && ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'menu_order title' );
			$query->set( 'order', 'ASC' );
		}
	}

	public static function enqueue_reorder_script( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::POST_TYPE !== $post_type || ! empty( $_GET['orderby'] ) || ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// ponytail: reindexes menu_order only within the current page of results; fine for the handful of locations a store typically has, revisit if lists regularly exceed one page.
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_add_inline_script(
			'jquery-ui-sortable',
			'jQuery(function($){$("#the-list").sortable({items:"tr",cursor:"move",axis:"y",update:function(){' .
			'var ids=$(this).sortable("toArray",{attribute:"id"}).map(function(v){return v.replace("post-","")});' .
			'$.post(ajaxurl,{action:"lps_reorder_locations",ids:ids,nonce:"' . esc_js( wp_create_nonce( 'lps_reorder_locations' ) ) . '"});' .
			'}});});'
		);
	}

	public static function ajax_reorder() {
		check_ajax_referer( 'lps_reorder_locations', 'nonce' );

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		foreach ( $ids as $index => $post_id ) {
			if ( self::POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'         => $post_id,
					'menu_order' => $index,
				)
			);
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}

		wp_send_json_success();
	}

	public static function require_address_before_publish( $data, $postarr ) {
		if ( self::POST_TYPE !== $data['post_type'] || 'publish' !== $data['post_status'] ) {
			return $data;
		}

		if ( isset( $_POST['lps_location_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lps_location_nonce'] ) ), 'lps_save_location' ) ) {
			$address = isset( $_POST['lps_address'] ) ? sanitize_text_field( wp_unslash( $_POST['lps_address'] ) ) : '';
		} else {
			$address = get_post_meta( $postarr['ID'], '_lps_address', true );
		}

		if ( '' === $address ) {
			$data['post_status'] = 'draft';
			add_filter( 'redirect_post_location', array( __CLASS__, 'add_missing_address_query_arg' ) );
		}

		return $data;
	}

	public static function add_missing_address_query_arg( $location ) {
		remove_filter( 'redirect_post_location', array( __CLASS__, 'add_missing_address_query_arg' ) );
		return add_query_arg( 'lps_missing_address', '1', $location );
	}

	public static function missing_address_notice() {
		global $pagenow, $typenow;
		if ( 'post.php' !== $pagenow || self::POST_TYPE !== $typenow || empty( $_GET['lps_missing_address'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'Pickup location was saved as draft because a street address is required before it can be published.', 'local-pickup-stores' ) . '</p></div>';
	}

	public static function add_settings_tab( $tabs ) {
		$tabs['pickup_locations'] = __( 'Pickup locations', 'local-pickup-stores' );
		return $tabs;
	}

	public static function redirect_settings_tab() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'wc-settings' === $page && 'pickup_locations' === $tab ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
			exit;
		}
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
			'address'      => array( __( 'Street address', 'local-pickup-stores' ), 'text' ),
			'city'         => array( __( 'City', 'local-pickup-stores' ), 'text' ),
			'state'        => array( __( 'State / County', 'local-pickup-stores' ), 'text' ),
			'postcode'     => array( __( 'Postcode', 'local-pickup-stores' ), 'text' ),
			'phone'        => array( __( 'Phone', 'local-pickup-stores' ), 'text' ),
			'notify_email' => array( __( 'Notification email', 'local-pickup-stores' ), 'email' ),
			'hours'        => array( __( 'Opening hours', 'local-pickup-stores' ), 'textarea' ),
			'price'        => array( __( 'Pickup price', 'local-pickup-stores' ), 'number' ),
		);

		echo '<table class="form-table"><tbody>';
		foreach ( $fields as $key => $field ) {
			$value = get_post_meta( $post->ID, '_lps_' . $key, true );
			echo '<tr><th scope="row"><label for="lps_' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
			if ( 'textarea' === $field[1] ) {
				echo '<textarea class="large-text" rows="3" id="lps_' . esc_attr( $key ) . '" name="lps_' . esc_attr( $key ) . '">' . esc_textarea( $value ) . '</textarea>';
			} else {
				if ( 'number' === $field[1] ) {
					$attributes = ' type="number" min="0" step="0.01"';
				} elseif ( 'email' === $field[1] ) {
					$attributes = ' type="email"';
				} else {
					$attributes = ' type="text"';
				}
				echo '<input class="regular-text"' . $attributes . ' id="lps_' . esc_attr( $key ) . '" name="lps_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 'price' === $key ) {
				echo '<p class="description">' . esc_html__( 'Leave empty or enter 0 for free pickup. The store currency is used.', 'local-pickup-stores' ) . '</p>';
			} elseif ( 'notify_email' === $key ) {
				echo '<p class="description">' . esc_html__( 'Optional. Sent a copy of new order emails in addition to the store\'s default admin email.', 'local-pickup-stores' ) . '</p>';
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

		$hours        = isset( $_POST['lps_hours'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lps_hours'] ) ) : '';
		$price        = isset( $_POST['lps_price'] ) ? wc_format_decimal( wp_unslash( $_POST['lps_price'] ) ) : '';
		$notify_email = isset( $_POST['lps_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['lps_notify_email'] ) ) : '';
		update_post_meta( $post_id, '_lps_hours', $hours );
		update_post_meta( $post_id, '_lps_price', $price );
		update_post_meta( $post_id, '_lps_notify_email', $notify_email );
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
