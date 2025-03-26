<?php

namespace WpifyWoo\Modules\IcDic;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use WP_Error;
use WpifyWooDeps\h4kuna\Ares\AresFactory;
use WpifyWooDeps\h4kuna\Ares\Exceptions\IdentificationNumberNotFoundException;


class BlockSupport {
	public $module = null;

	public function __construct( $module ) {
		$this->module = $module;
		add_action( 'woocommerce_init', [ $this, 'add_checkout_fields' ] );
		add_action( 'wp_footer', [ $this, 'add_placeholder' ] );
		//add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'save_metadata' ] );
		add_filter( 'woocommerce_set_additional_field_value', [ $this, 'save_metadata_back_compatibility' ], 10, 4 );
		add_action( 'woocommerce_sanitize_additional_field', [ $this, 'sanitize_ic_dic_fields' ] );
		add_filter( 'woocommerce_store_api_cart_errors', [ $this, 'validate_cart' ], 10, 2 );
		// Default values from meta (Backward compatibility)
		add_filter( 'woocommerce_get_default_value_for_wpify/company', function ( $value, $group, $wc_object ) {
			return $wc_object->get_billing_company();
		}, 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_my-wpify/ic', function ( $value, $group, $wc_object ) {
			return $wc_object->get_meta( '_billing_ic' );
		}, 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_my-wpify/dic', function ( $value, $group, $wc_object ) {
			return $wc_object->get_meta( '_billing_dic' );
		}, 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_my-wpify/dic-dph', function ( $value, $group, $wc_object ) {
			return $wc_object->get_meta( '_billing_dic_dph' );
		}, 10, 3 );
		add_action( 'woocommerce_validate_additional_field', [ $this, 'validate_ic_dic_fields' ], 10, 3 );

		$this->register_vat_exempt_callback();
	}

	public function add_checkout_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'wpify/ic_dic_toggle',
				'label'    => __( 'I\'m shopping for a company', 'wpify-woo' ),
				'location' => 'contact',
				'type'     => 'checkbox',
				'default'  => 0,
			),
		);
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => 'wpify/ic',
				'label'             => __( 'Identification no.', 'wpify-woo' ),
				'location'          => 'contact',
				'type'              => 'text',
				'meta_key'          => '_billing_ic',
				'sanitize_callback' => function ( $field_value ) {
					return str_replace( ' ', '', $field_value );
				},
			),

		);
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'wpify/company',
				'label'    => __( 'Company', 'wpify-woo' ),
				'location' => 'contact',
				'type'     => 'text',

			),

		);
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => 'wpify/dic',
				'label'             => __( 'VAT no.', 'wpify-woo' ),
				'location'          => 'contact',
				'type'              => 'text',
				'meta_key'          => '_billing_dic',
				'sanitize_callback' => function ( $field_value ) {
					return str_replace( ' ', '', $field_value );
				},
			),
		);
		woocommerce_register_additional_checkout_field(
			array(
				'id'                => 'wpify/dic-dph',
				'label'             => __( 'In VAT no.', 'wpify-woo' ),
				'location'          => 'contact',
				'type'              => 'text',
				'meta_key'          => '_billing_dic_dph',
				'sanitize_callback' => function ( $field_value ) {
					return str_replace( ' ', '', $field_value );
				},
			),
		);
	}

	public function add_placeholder() {
		echo '<div data-app="wpify-ic-dic"></div>';
	}

	public function register_vat_exempt_callback() {
		woocommerce_store_api_register_update_callback(
			[
				'namespace' => 'wpify_ic_dic',
				'callback'  => array( $this, 'set_customer_vat_extempt' ),
			]
		);
	}

	public function sanitize_ic_dic_fields( $value, $key ) {
		if ( in_array( $key, array( 'wpify/ic', 'wpify/dic', 'wpify/dic-dph' ) ) ) {
			$value = str_replace( ' ', '', $value );
			$value = strtoupper( $value );
		}

		return $value;
	}

	public function validate_ic_dic_fields( \WP_Error $errors, $field_key, $field_value ) {
		if ( $field_key !== 'wpify/dic' && $field_key !== 'wpify/dic-dph' && $field_key !== 'wpify/ic' ) {
			return;
		}

		wc_load_cart();
		$country = WC()->session->customer['country'];


		if ( 'wpify/ic' === $field_key && $this->module->get_setting( 'validate_ares' )
		     && $country === 'CZ'
		     && in_array( 'order_submit', $this->module->get_setting( 'validate_ares' ) )
		) {
			$ares = ( new AresFactory() )->create();
			$ic   = sanitize_text_field( $field_value );

			if ( ! is_numeric( $ic ) ) {
				$errors->add( 'validation', __( 'Please enter valid IC', 'wpify-woo' ) );
			} else {
				try {
					$ares->loadBasic( $ic );
				} catch ( IdentificationNumberNotFoundException $e ) {
					$errors->add( 'validation', __( 'The entered Company Number has not been found in ARES, please enter valid company number.', 'wpify-woo' ) );
				}
			}
		}

		if ( $this->module->get_setting( 'validate_vies' ) && $this->module->get_setting( 'vies_fails' ) !== true ) {
			if ( $country === 'SK' && $field_key !== 'wpify/dic-dph' ) {
				return $errors;
			} else if ( $field_key !== 'wpify/dic' ) {
				return $errors;
			}

			if ( ! empty( $field_value ) && ! $this->module->is_valid_dic( $field_value ) ) {
				if ( $country === 'SK' ) {
					$errors->add( 'validation', __( 'The entered IN VAT Number has not been found in VIES, please enter valid IN VAT number.', 'wpify-woo' ) );
				} else {
					$errors->add( 'validation', __( 'The entered VAT Number has not been found in VIES, please enter valid VAT number.', 'wpify-woo' ) );
				}
			}
		}

		return $errors;
	}

	public function save_metadata( $order ) {
		$checkout_fields                 = Package::container()->get( CheckoutFields::class );
		$order_additional_billing_fields = $checkout_fields->get_all_fields_from_object( $order ); // array( 'my-plugin-namespace/my-field' => 'my-value' );

		if ( isset( $order_additional_billing_fields['wpify/company'] ) ) {
			update_post_meta( $order->get_id(), '_billing_company', $order_additional_billing_fields['wpify/company'] );
		}

		if ( isset( $order_additional_billing_fields['wpify/ic'] ) ) {
			update_post_meta( $order->get_id(), '_billing_ic', $order_additional_billing_fields['wpify/ic'] );
		}

		if ( isset( $order_additional_billing_fields['wpify/dic'] ) ) {
			update_post_meta( $order->get_id(), '_billing_dic', $order_additional_billing_fields['wpify/dic'] );
		}

		if ( isset( $order_additional_billing_fields['wpify/dic-dph'] ) ) {
			update_post_meta( $order->get_id(), '_billing_dic_dph', $order_additional_billing_fields['wpify/dic-dph'] );
		}
	}

	public function save_metadata_back_compatibility( $key, $value, $group, $wc_object ) {
		if ( $key === 'wpify/company' ) {
			$wc_object->set_billing_company( $value );
			$wc_object->save();

			return;
		}

		$meta_key = match ( $key ) {
			'wpify/ic' => '_billing_ic',
			'wpify/dic' => '_billing_dic',
			'wpify/dic-dph' => '_billing_dic_dph',
			default => null,
		};

		if ( $meta_key ) {
			$wc_object->update_meta_data( $meta_key, $value, true );
		}
	}

	public function set_customer_vat_extempt( $data ) {
		if ( isset( $data['validation'] ) && $data['validation'] === 'passed' ) {
			WC()->customer->set_is_vat_exempt( true );
		}
	}

	public function validate_cart( $cart_errors, $cart ) {
		return $cart_errors;
	}
}
