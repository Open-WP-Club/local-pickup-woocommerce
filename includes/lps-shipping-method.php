<?php

defined( 'ABSPATH' ) || exit;

class LPS_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );

		$this->id                 = 'lps_local_pickup';
		$this->method_title       = __( 'Store pickup', 'local-pickup-stores' );
		$this->method_description = __( 'Let customers collect their order from a selected store or pickup location.', 'local-pickup-stores' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal', 'local-pickup' );

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_instance_form_fields();
		$this->init_instance_settings();

		$this->title      = $this->get_option( 'title', __( 'Store pickup', 'local-pickup-stores' ) );
		$this->tax_status = 'taxable';
		$this->enabled    = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array();
	}

	public function init_instance_form_fields() {
		$options = array();
		foreach ( LPS_Locations::get_locations( false ) as $location ) {
			$options[ $location->ID ] = $location->post_title;
		}

		$this->instance_form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'local-pickup-stores' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable store pickup for this shipping zone', 'local-pickup-stores' ),
				'default' => 'yes',
			),
			'title'            => array(
				'title'       => __( 'Method title', 'local-pickup-stores' ),
				'type'        => 'text',
				'description' => __( 'Shown to customers in the Checkout Block.', 'local-pickup-stores' ),
				'default'     => __( 'Store pickup', 'local-pickup-stores' ),
				'desc_tip'    => true,
			),
			'locations'        => array(
				'title'       => __( 'Available locations', 'local-pickup-stores' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'css'         => 'width: 400px;',
				'description' => __( 'Choose the locations available in this zone. Leave empty to use every enabled location.', 'local-pickup-stores' ),
				'options'     => $options,
				'desc_tip'    => true,
			),
			'default_location' => array(
				'title'       => __( 'Default location', 'local-pickup-stores' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'css'         => 'width: 400px;',
				'description' => __( 'This location is selected first when pickup is initially chosen. It must also be available in this zone.', 'local-pickup-stores' ),
				'default'     => '',
				'options'     => array( '' => __( 'First available location', 'local-pickup-stores' ) ) + $options,
				'desc_tip'    => true,
			),
		);
	}

	public function is_available( $package ) {
		if ( ! parent::is_available( $package ) ) {
			return false;
		}

		$allowed_ids = array_filter( array_map( 'absint', (array) $this->get_option( 'locations', array() ) ) );

		foreach ( LPS_Locations::get_locations( true ) as $location ) {
			if ( ! $allowed_ids || in_array( $location->ID, $allowed_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	public function calculate_shipping( $package = array() ) {
		$allowed_ids         = array_filter( array_map( 'absint', (array) $this->get_option( 'locations', array() ) ) );
		$default_location_id = absint( $this->get_option( 'default_location', 0 ) );
		$locations           = LPS_Locations::get_locations( true );

		if ( $default_location_id ) {
			foreach ( $locations as $index => $location ) {
				if ( $location->ID === $default_location_id ) {
					unset( $locations[ $index ] );
					array_unshift( $locations, $location );
					break;
				}
			}
		}

		foreach ( $locations as $location ) {
			if ( $allowed_ids && ! in_array( $location->ID, $allowed_ids, true ) ) {
				continue;
			}

			$price      = (float) get_post_meta( $location->ID, '_lps_price', true );
			$tax_status = get_post_meta( $location->ID, '_lps_tax_status', true );
			$address    = array(
				'address_1' => get_post_meta( $location->ID, '_lps_address', true ),
				'city'      => get_post_meta( $location->ID, '_lps_city', true ),
				'state'     => get_post_meta( $location->ID, '_lps_state', true ),
				'postcode'  => get_post_meta( $location->ID, '_lps_postcode', true ),
				'country'   => get_post_meta( $location->ID, '_lps_country', true ),
			);

			$this->tax_status = 'none' === $tax_status ? 'none' : 'taxable';
			$this->add_rate(
				array(
					'id'        => $this->get_rate_id() . ':' . $location->ID,
					'label'     => $location->post_title,
					'cost'      => $price,
					'package'   => $package,
					'meta_data' => array(
						'_lps_location_id'         => $location->ID,
						'_lps_location_name'       => $location->post_title,
						'_lps_location_address'    => LPS_Locations::get_address( $location->ID ),
						'_lps_location_hours'      => get_post_meta( $location->ID, '_lps_hours', true ),
						'_lps_location_phone'      => get_post_meta( $location->ID, '_lps_phone', true ),
						'_pickup_location_address' => $address,
					),
				)
			);
		}
	}
}
