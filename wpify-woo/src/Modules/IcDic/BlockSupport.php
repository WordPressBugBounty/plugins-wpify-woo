<?php

namespace WpifyWoo\Modules\IcDic;

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use WP_Error;
use WpifyWooDeps\h4kuna\Ares\AresFactory;
use WpifyWooDeps\h4kuna\Ares\Exceptions\IdentificationNumberNotFoundException;


class BlockSupport {
	public $module = null;
	private $current_checkout_country = null;

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
		add_filter( 'woocommerce_get_default_value_for_wpify/ic', function ( $value, $group, $wc_object ) {
			return $wc_object->get_meta( '_billing_ic' );
		}, 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_wpify/dic', function ( $value, $group, $wc_object ) {
			return $wc_object->get_meta( '_billing_dic' );
		}, 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_wpify/dic-dph', function ( $value, $group, $wc_object ) {
			return $wc_object->get_meta( '_billing_dic_dph' );
		}, 10, 3 );
		add_action( 'woocommerce_validate_additional_field', [ $this, 'validate_ic_dic_fields' ], 10, 3 );
		// Hook to capture current country from checkout data during validation
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ $this, 'capture_country_before_validation' ], 5, 2 );

		// Final VIES validation on order submission (both checkouts)
		add_action( 'rest_api_init', [ $this, 'register_block_checkout_vies_validation' ], 5 );

		// Ensure VAT exempt is set before order totals calculation
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', [ $this, 'ensure_vat_exempt_from_checkout_data' ], 20, 2 );

		// Log VAT exempt decision after order is created
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'log_order_vat_exempt_decision' ] );

		// Hide IC/DIC fields from My Account > Account Details page using CSS
		add_action( 'woocommerce_edit_account_form_start', [ $this, 'hide_ic_dic_fields_with_css' ] );

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

	public function sanitize_ic_dic_fields( $value, $key = null ) {
		// Handle both old and new callback signatures
		if ( $key === null && is_string( $value ) ) {
			// Skip sanitization without key to avoid affecting company field
			return $value;
		} elseif ( $key !== null && in_array( $key, array( 'wpify/ic', 'wpify/dic', 'wpify/dic-dph' ) ) ) {
			// Only sanitize IC/DIC fields
			$value = str_replace( ' ', '', $value );
			$value = strtoupper( $value );
		}

		return $value;
	}

	public function validate_ic_dic_fields( \WP_Error $errors, $field_key, $field_value ) {
		if ( $field_key !== 'wpify/dic' && $field_key !== 'wpify/dic-dph' && $field_key !== 'wpify/ic' ) {
			return $errors;
		}

		// Get country from multiple sources - block checkout may have newer data
		$country = null;

		// Check php://input for block checkout data first (most reliable)
		$input = file_get_contents( 'php://input' );
		if ( $input ) {
			$input_data = json_decode( $input, true );
			if ( ! empty( $input_data['billing_address']['country'] ) ) {
				$country = sanitize_text_field( $input_data['billing_address']['country'] );
			}
		}

		// Try captured country from our hook
		if ( empty( $country ) && ! empty( $this->current_checkout_country ) ) {
			$country = $this->current_checkout_country;
		}

		// Try to get country from POST data
		if ( empty( $country ) && ! empty( $_POST['billing_country'] ) ) {
			$country = sanitize_text_field( $_POST['billing_country'] );
		}

		// Try from additional fields (for block checkout)
		if ( empty( $country ) && ! empty( $_POST['wc-additional-fields-data'] ) ) {
			$additional_data = json_decode( stripslashes( $_POST['wc-additional-fields-data'] ), true );
			if ( ! empty( $additional_data['billing_country'] ) ) {
				$country = sanitize_text_field( $additional_data['billing_country'] );
			}
		}


		// Fallback to customer object
		if ( empty( $country ) && ! empty( WC()->customer ) ) {
			$country = WC()->customer->get_billing_country();
		}

		// Last resort - session
		if ( empty( $country ) ) {
			wc_load_cart();
			$country = WC()->session->customer['country'] ?? '';
		}

		// Validation processing - detailed logging moved to order creation

		// For IC field, skip server-side validation for block checkout since we can't reliably get current country
		if ( 'wpify/ic' === $field_key ) {
			return $errors; // Skip IC validation for block checkout - frontend handles it
		}

		// ARES validation only for Czech IC numbers
		if ( 'wpify/ic' === $field_key
		     && $this->module->get_setting( 'validate_ares' )
		     && $country === 'CZ'
		     && in_array( 'order_submit', $this->module->get_setting( 'validate_ares' ) )
		     && ! empty( $field_value )
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

		// VIES validation moved to final order submission - no longer validate during field input

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
		// Default: always reset VAT exempt first
		WC()->customer->set_is_vat_exempt( false );

		// Handle different validation states first
		if ( isset( $data['validation'] ) && ( $data['validation'] === 'dic_cleared' || $data['validation'] === 'failed' ) ) {
			// DIC was cleared or validation failed - ensure VAT exempt is false
			if ( $data['validation'] === 'dic_cleared' ) {
				// Clear meta data only when explicitly cleared, not when validation fails
				WC()->customer->delete_meta_data( 'billing_dic' );
				WC()->customer->delete_meta_data( 'billing_dic_dph' );
				WC()->customer->save();
			}
			return;
		}

		// Get current checkout data to determine if VAT exempt should be applied
		$vies_fails            = $this->module->get_setting( 'vies_fails' );
		$vat_extempt_countries = $this->module->get_setting( 'zero_tax_for_vat_countries' );

		// Check if VAT exempt countries are configured
		if ( empty( $vat_extempt_countries ) ) {
			return;
		}

		// Get country and DIC from multiple sources to ensure we have the latest data
		$country = $data['country'] ?? WC()->customer->get_billing_country();
		$dic = null;

		// Try to get DIC from the data passed, otherwise from customer meta
		if ( isset( $data['dic'] ) ) {
			// DIC explicitly provided in data (including empty string)
			$dic = $data['dic'];
		} else {
			// Get DIC from customer meta based on country only if not provided in data
			if ( $country === 'SK' ) {
				$dic = WC()->customer->get_meta( 'billing_dic_dph' );
			} else {
				$dic = WC()->customer->get_meta( 'billing_dic' );
			}
		}

		// If we have a DIC, check if VAT exempt should be applied
		if ( ! empty( $dic ) ) {
			// If VIES validation is strict (vies_fails is false) and validation hasn't passed, don't set VAT exempt
			if ( isset( $data['validation'] ) && $data['validation'] === 'passed' ) {
				// Validation explicitly passed - proceed with VAT exempt check
				$this->apply_vat_exempt_if_valid( $dic, $country );
			} elseif ( empty( $vies_fails ) || $vies_fails !== false ) {
				// VIES validation is lenient (vies_fails is true) OR not configured - check VAT exempt anyway
				$this->apply_vat_exempt_if_valid( $dic, $country );
			}
			// If VIES is strict and validation hasn't passed, keep VAT exempt false
		}
		// If no DIC, VAT exempt stays false (already set at the beginning)

	}

	private function apply_vat_exempt_if_valid( $dic, $country ) {
		// For block checkout, use the billing country from DIC validation as shipping country
		// since the customer session might not be updated yet with the current country
		$shipping_country = WC()->customer->get_shipping_country();
		if ( empty( $shipping_country ) || $shipping_country === 'CZ' ) {
			// Fallback to the country from DIC validation
			$shipping_country = $country;
		}

		$is_vat_exempt = $this->module->is_vat_extempt( $dic, $shipping_country );
		WC()->customer->set_is_vat_exempt( $is_vat_exempt );
	}


	public function capture_country_before_validation( $customer, $request ) {
		// Capture country from request data before validation
		$data = $request->get_json_params();
		if ( ! empty( $data['billing_address']['country'] ) ) {
			$this->current_checkout_country = $data['billing_address']['country'];
		}
	}

	public function ensure_vat_exempt_from_checkout_data( $customer, $request ) {
		// This runs during final checkout processing to ensure VAT exempt is correctly applied
		$data = $request->get_json_params();
		$billing_country = $data['billing_address']['country'] ?? '';
		$additional_fields = $data['additional_fields'] ?? array();

		// Get DIC based on country
		$dic = null;
		if ( $billing_country === 'SK' && ! empty( $additional_fields['wpify/dic-dph'] ) ) {
			$dic = $additional_fields['wpify/dic-dph'];
		} elseif ( $billing_country !== 'SK' && ! empty( $additional_fields['wpify/dic'] ) ) {
			$dic = $additional_fields['wpify/dic'];
		}

		// Always reset VAT exempt first
		WC()->customer->set_is_vat_exempt( false );

		// Apply VAT exempt only if we have a valid DIC and VAT exempt countries are configured
		$vat_extempt_countries = $this->module->get_setting( 'zero_tax_for_vat_countries' );
		if ( ! empty( $dic ) && ! empty( $vat_extempt_countries ) ) {
			$is_vat_exempt = $this->module->is_vat_extempt( $dic, $billing_country );
			WC()->customer->set_is_vat_exempt( $is_vat_exempt );
		}
	}

	public function ensure_vat_exempt_before_totals( $customer, $request ) {
		// This runs late in the checkout process to ensure VAT exempt is applied before final totals
		$data = $request->get_json_params();
		$billing_country = $data['billing_address']['country'] ?? '';
		$additional_fields = $data['additional_fields'] ?? array();

		// Get DIC based on country
		$dic = null;
		if ( $billing_country === 'SK' && ! empty( $additional_fields['wpify/dic-dph'] ) ) {
			$dic = $additional_fields['wpify/dic-dph'];
		} elseif ( $billing_country !== 'SK' && ! empty( $additional_fields['wpify/dic'] ) ) {
			$dic = $additional_fields['wpify/dic'];
		}

		// Always reset VAT exempt first
		WC()->customer->set_is_vat_exempt( false );

		// Apply VAT exempt only if we have a valid DIC and VAT exempt countries are configured
		$vat_extempt_countries = $this->module->get_setting( 'zero_tax_for_vat_countries' );
		if ( ! empty( $dic ) && ! empty( $vat_extempt_countries ) ) {
			$this->apply_vat_exempt_if_valid( $dic, $billing_country );
		}
	}

	public function validate_cart( $cart_errors, $cart ) {
		return $cart_errors;
	}

	/**
	 * Register VIES validation for block checkout on final order submission
	 */
	public function register_block_checkout_vies_validation() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		// Register VIES validation on final checkout submission for blocks
		add_action( 'rest_pre_dispatch', array( $this, 'validate_vies_before_block_checkout' ), 10, 3 );
	}

	/**
	 * Validate VIES before block checkout is processed (only on final submission)
	 */
	public function validate_vies_before_block_checkout( $result, $server, $request ) {
		if ( $request->get_route() !== '/wc/store/v1/checkout' || $request->get_method() !== 'POST' ) {
			return $result;
		}

		// Only validate if VIES validation is enabled and vies_fails is false (strict mode)
		if ( ! $this->module->get_setting( 'validate_vies' ) || $this->module->get_setting( 'vies_fails' ) === true ) {
			return $result;
		}

		$body = $request->get_json_params();
		$additional_fields = $body['additional_fields'] ?? array();
		$billing_country = $body['billing_address']['country'] ?? '';

		$validation_errors = array();

		// Validate DIC fields based on country
		if ( $billing_country === 'SK' ) {
			// For Slovakia, validate dic-dph field
			$dic_dph = $additional_fields['wpify/dic-dph'] ?? '';
			if ( ! empty( $dic_dph ) && ! $this->module->is_valid_dic( $dic_dph ) ) {
				$validation_errors[] = array(
					'code'    => 'dic_dph_invalid',
					'message' => __( 'The entered IN VAT Number has not been found in VIES, please enter valid IN VAT number.', 'wpify-woo' ),
					'data'    => array( 'field' => 'wpify/dic-dph' )
				);
			}
		} else {
			// For other countries, validate dic field
			$dic = $additional_fields['wpify/dic'] ?? '';
			if ( ! empty( $dic ) && ! $this->module->is_valid_dic( $dic ) ) {
				$validation_errors[] = array(
					'code'    => 'dic_invalid',
					'message' => __( 'The entered VAT Number has not been found in VIES, please enter valid VAT number.', 'wpify-woo' ),
					'data'    => array( 'field' => 'wpify/dic' )
				);
			}
		}

		if ( ! empty( $validation_errors ) ) {
			$combined_messages = array_column( $validation_errors, 'message' );

			return new \WP_Error(
				'vies_validation_failed',
				implode( '<br>', $combined_messages ),
				array(
					'status'            => 400,
					'validation_errors' => $validation_errors
				)
			);
		}


		return $result;
	}

	public function log_order_vat_exempt_decision( $order ) {
		// Only log if DIC was provided
		$billing_country = $order->get_billing_country();
		$dic = $billing_country === 'SK'
			? $order->get_meta('_billing_dic_dph')
			: $order->get_meta('_billing_dic');

		if ( empty( $dic ) ) {
			return; // No DIC provided, skip logging
		}

		// Detect VAT exempt from order - if tax_total is 0 but order has taxable items, likely VAT exempt
		$customer_vat_exempt = ($order->get_total_tax() == 0 && $order->get_total() > 0);
		$vat_exempt_countries = $this->module->get_setting( 'zero_tax_for_vat_countries' );
		$shop_country = wc_get_base_location()['country'];
		$shipping_country = $order->get_shipping_country();

		// Determine if VAT should be exempt based on current logic
		$should_be_vat_exempt = false;
		if ( ! empty( $vat_exempt_countries ) ) {
			$should_be_vat_exempt = $this->module->is_vat_extempt( $dic, $shipping_country );
		}

		$this->module->log->info('Order VAT Exempt Decision', [
			'order_id' => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'billing_country' => $billing_country,
			'shipping_country' => $shipping_country,
			'shop_country' => $shop_country,
			'submitted_dic' => $dic,
			'customer_vat_exempt' => $customer_vat_exempt,
			'should_be_vat_exempt' => $should_be_vat_exempt,
			'vat_exempt_countries' => $vat_exempt_countries,
			'order_total' => $order->get_total(),
			'tax_total' => $order->get_total_tax(),
			'context' => 'Order created - Block checkout'
		]);
	}

	/**
	 * Hide IC/DIC fields from My Account > Account Details page using CSS
	 * These fields should only be visible in the Billing Address section
	 */
	public function hide_ic_dic_fields_with_css() {
		// Only output CSS on the account edit page
		if ( ! is_account_page() ) {
			return;
		}
		?>
		<style type="text/css">
			/* Hide IC/DIC fields from My Account > Account Details form */
			.woocommerce-EditAccountForm p[id*="wpify/ic_dic_toggle"],
			.woocommerce-EditAccountForm p[id*="wpify/ic"],
			.woocommerce-EditAccountForm p[id*="wpify/dic"],
			.woocommerce-EditAccountForm p[id*="wpify/dic-dph"],
			.woocommerce-EditAccountForm p[id*="wpify/company"] {
				display: none !important;
			}
		</style>
		<?php
	}
}
