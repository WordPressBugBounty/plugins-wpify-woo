<?php

namespace WpifyWoo\Modules\IcDic;

use Exception;
use WC_Data;
use WC_Order;
use WpifyWoo\Plugin;
use WpifyWoo\WooCommerceIntegration;
use WpifyWooDeps\Wpify\WooCore\Abstracts\AbstractModule;
use WpifyWoo\Managers\ApiManager;
use WpifyWoo\Modules\IcDic\Api\IcDicApi;
use WpifyWooDeps\DragonBe\Vies\Vies;
use WpifyWooDeps\DragonBe\Vies\ViesException;
use WpifyWooDeps\DragonBe\Vies\ViesServiceException;
use WpifyWooDeps\h4kuna\Ares;
use WpifyWooDeps\h4kuna\Ares\Exceptions\IdentificationNumberNotFoundException;
use WpifyWooDeps\Wpify\Asset\AssetFactory;
use WpifyWooDeps\Wpify\PluginUtils\PluginUtils;
use WpifyWooDeps\Wpify\Log\RotatingFileLog;

/**
 * Class IcDicModule
 *
 * @package WpifyWoo\Modules\IcDic
 */
class IcDicModule extends AbstractModule {
	const MODULE_ID = 'ic_dic';

	public function __construct(
		private AssetFactory $asset_factory,
		private PluginUtils $plugin_utils,
		private ApiManager $api_manager,
		private WooCommerceIntegration $woo_integration,
		public RotatingFileLog $log,
	) {
		parent::__construct();
		$this->setup();
	}

	/**
	 * Setup
	 *
	 * @return void
	 */
	public function setup() {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'adjust_checkout_fields' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'adjust_fields_priority' ) );
		add_filter( 'woocommerce_order_formatted_billing_address', array( $this, 'add_fields_to_address' ), 10, 2 );
		add_filter( 'woocommerce_formatted_address_replacements', array( $this, 'replace_tags_in_emails' ), 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', array( $this, 'localisation_address_formats' ) );
//		add_filter( 'woocommerce_admin_order_data_after_billing_address', array(
//			$this,
//			'display_block_fields_in_admin'
//		) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'log_order_vat_exempt_decision' ), 10, 3 );
		add_action( 'init', array( $this, 'add_rest_api' ) );

		if ( $this->get_setting( 'autofill_ares' ) ) {
			if ( 'before_customer_details' === $this->get_setting( 'autofill_ares_position' ) ) {
				add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'render_ares' ) );
			} elseif ( 'after_company_checkbox' === $this->get_setting( 'autofill_ares_position' ) ) {
				add_filter( 'woocommerce_form_field', [ $this, 'add_ares_autofill_to_company_field' ], 10, 2 );
			} elseif ( 'after_ic_field' === $this->get_setting( 'autofill_ares_position' ) ) {
				add_filter( 'woocommerce_form_field', [ $this, 'add_ares_autofill_to_ic_field' ], 10, 2 );
			}
		}

		add_filter( 'woocommerce_billing_fields', array( $this, 'in_vat_woocommerce_billing' ) );
		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'in_vat_woocommerce_billing_admin' ) );
		add_filter( 'woocommerce_customer_meta_fields', array( $this, 'in_vat_woocommerce_billing_profile' ), 10, 1 );
		add_filter(
			'woocommerce_my_account_my_address_formatted_address',
			array(
				$this,
				'add_in_vat_to_address',
			),
			10,
			3
		);
		add_action( 'wp', array( $this, 'set_customer_vat_extempt' ) ); // Only on frontend pages, not admin/ajax
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'set_vat_extempt_on_order_review' ) );
		add_filter( 'post_class', array( $this, 'add_post_class' ), 10, 3 );
		add_filter( 'woocommerce_ajax_get_customer_details', array( $this, 'autofill_vat_fields_in_admin' ), 10, 3 );


		new BlockSupport( $this );
	}

	/**
	 * Module ID
	 *
	 * @return string
	 */
	public function id(): string {
		return self::MODULE_ID;
	}

	/**
	 * Module name
	 *
	 * @return mixed
	 */
	public function name() {
		return __( 'Checkout IČ and DIČ', 'wpify-woo' );
	}

	/**
	 * Plugin slug
	 *
	 * @return string
	 */

	public function plugin_slug(): string {
		return Plugin::PLUGIN_SLUG;
	}

	/**
	 * Module documentation url
	 *
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://wpify.io/dokumentace/wpify-woo/ic-dic/';
	}

	/**
	 * Enqueue frontend scripts
	 */
	public function enqueue_scripts() {
		if ( ! is_checkout() && ! is_account_page() ) {
			return;
		}

		$this->asset_factory->wp_script( $this->plugin_utils->get_plugin_path( 'build/icdic.css' ) );
		$this->asset_factory->wp_script( $this->plugin_utils->get_plugin_path( 'build/icdic.js' ), array(
			'handle'    => 'wpify-woo-ic-dic',
			'in_footer' => true,
			'variables' => array(
				'wpifyWooIcDic' => array(
					'restUrl'           => $this->api_manager->get_rest_url(),
					'position'          => $this->get_setting( 'autofill_ares_position' ),
					'requireCompany'    => 'hidden' !== get_option( 'woocommerce_checkout_company_field', 'optional' ) ? $this->get_setting( 'required_company' ) : false,
					'moveCompany'       => $this->get_setting( 'move_company_field' ),
					'requireVatFields'  => $this->get_setting( 'required_ic' ),
					'optionalText'      => '(' . esc_html__( 'optional', 'woocommerce' ) . ')',
					'changePlaceholder' => $this->get_setting( 'change_placeholder' ),
					'checkingText'      => __( 'Checking in', 'wpify-woo' ),
					'validateAres'      => $this->get_setting( 'validate_ares' ),
				),
			),
		) );
		add_action( 'wp_enqueue_scripts', function () {
			$this->asset_factory->wp_script( $this->plugin_utils->get_plugin_path( 'build/icdic-blocks.js' ), array(
				'handle'       => 'wpify-woo-ic-dic-blocks',
				'in_footer'    => true,
				'variables'    => array(
					'wpifyWooIcDic' => array(
						'restUrl'           => $this->api_manager->get_rest_url(),
						'position'          => $this->get_setting( 'autofill_ares_position' ),
						'requireCompany'    => 'hidden' !== get_option( 'woocommerce_checkout_company_field', 'optional' ) ? $this->get_setting( 'required_company' ) : false,
						'moveCompany'       => $this->get_setting( 'move_company_field' ),
						'requireVatFields'  => $this->get_setting( 'required_ic' ),
						'optionalText'      => '(' . esc_html__( 'optional', 'woocommerce' ) . ')',
						'changePlaceholder' => $this->get_setting( 'change_placeholder' ),
						'checkingText'      => __( 'Checking in', 'wpify-woo' ),
						'autofillAresText'  => $this->get_setting( 'autofill_ares_text' ) ?: __( 'Autofill from Ares', 'wpify-woo' ),
						'searchAresText'    => $this->get_setting( 'submit_ares_text' ) ?: __( 'Search in Ares', 'wpify-woo' ),
						'validateVies'      => $this->get_setting( 'validate_vies' ),
						'viesFails'         => $this->get_setting( 'vies_fails' ),
						'validateAres'      => $this->get_setting( 'validate_ares' ),
					),
				),
				'dependencies' => array( 'wc-blocks-data-store', 'wc-blocks-checkout' ),
			) );
		}, 1000 );
	}


	/**
	 * Add settings
	 *
	 * @return array
	 */
	public function settings(): array {
		return array(
			array(
				'id'    => 'move_company_field',
				'type'  => 'toggle',
				'label' => __( 'Move company field', 'wpify-woo' ),
				'title' => __( 'Check if you want to move the company field to the extra VAT fields or under the checkbox "I\'m shopping for a company" if enabled.', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'    => 'move_vat_fields',
				'type'  => 'toggle',
				'label' => __( 'Move VAT fields', 'wpify-woo' ),
				'title' => __( 'Check if you want to move the VAT fields to the top of the checkout form to the "Company" field', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'    => 'show_checkbox',
				'type'  => 'toggle',
				'label' => __( 'Show "I\'m shopping for a company" checkbox', 'wpify-woo' ),
				'title' => __( 'Check if want to show the checkbox "I\'m shopping for a company" - the extra fields will show only if the checkbox is checked.', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'    => 'narrow_vat_fields',
				'type'  => 'toggle',
				'label' => __( 'Half width VAT fields', 'wpify-woo' ),
				'title' => __( 'Check if you want to display VAT fields in half width side by side in the checkout.', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'    => 'change_placeholder',
				'type'  => 'toggle',
				'label' => __( 'Placeholder as number', 'wpify-woo' ),
				'title' => __( 'Check if you want the placeholder of VAT fields to be an example of how to fill the field.', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'    => 'validate_format',
				'type'  => 'toggle',
				'label' => __( 'Validate number format', 'wpify-woo' ),
				'title' => __( 'Check if you want to check if the numbers entered are in a valid format when sending order. Checks for States CZ, SK, PL, HU, DE', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'    => 'required_company',
				'type'  => 'toggle',
				'label' => __( 'Required "Company" field for companies', 'wpify-woo' ),
				'title' => __( 'Check if you want to set "Company" field as required if the checkbox "I\'m shopping for a company" is checked.', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'      => 'required_ic',
				'type'    => 'select',
				'label'   => __( 'Required identification number field for companies', 'wpify-woo' ),
				'desc'    => sprintf( '%s %s', __( 'Choose when the identification number field to be required.', 'wpify-woo' ), __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ) ),
				'options' => array(
					array(
						'label' => __( 'If the "I\'m shopping for a company" is checked', 'wpify-woo' ),
						'value' => 'if_checkbox',
					),
					array(
						'label' => __( 'If the company field is filled', 'wpify-woo' ),
						'value' => 'if_company',
					),
				),
			),
			array(
				'id'      => 'validate_ares',
				'type'    => 'multi_toggle',
				'label'   => __( 'Validate entered identification number from ARES', 'wpify-woo' ),
				'desc'    => __( 'Check if want to validate the entered identification number with ARES.', 'wpify-woo' ),
				'options' => array(
					array(
						'label' => __( 'After entering identification number on the checkout form', 'wpify-woo' ),
						'value' => 'ic_entered',
					),
					array(
						'label' => __( 'After submitting the order', 'wpify-woo' ),
						'value' => 'order_submit',
					),
				),
			),
			array(
				'id'    => 'autofill_ares',
				'type'  => 'toggle',
				'label' => __( 'Autofill from ARES', 'wpify-woo' ),
				'title' => __( 'Enable if you want to display "Fill automatically from ARES" at the top of checkout form.', 'wpify-woo' ),
				'desc'  => __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ),
			),
			array(
				'id'            => 'autofill_ares_text',
				'type'          => 'text',
				'label'         => __( 'Autofill from ARES text', 'wpify-woo' ),
				'desc'          => __( 'Enter the text that will appear on top of the checkout.', 'wpify-woo' ),
				'default_value' => __( 'Autofill from Ares', 'wpify-woo' ),
			),
			array(
				'id'            => 'submit_ares_text',
				'type'          => 'text',
				'label'         => __( 'ARES submit button text', 'wpify-woo' ),
				'desc'          => __( 'Enter the text for the button sending the request to ARES.', 'wpify-woo' ),
				'default_value' => __( 'Search in ARES', 'wpify-woo' ),
			),
			array(
				'id'            => 'autofill_ares_position',
				'type'          => 'select',
				'label'         => __( 'Autofill ARES position', 'wpify-woo' ),
				'desc'          => sprintf( '%s %s', __( 'Select the position for the autofill.', 'wpify-woo' ), __( 'Valid for classic checkout only. Does not affect block checkout.', 'wpify-woo' ) ),
				'default_value' => 'before_customer_details',
				'options'       => [
					[
						'label' => __( 'Before customer details', 'wpify-woo' ),
						'value' => 'before_customer_details',
					],
					[
						'label' => __( 'After "I\'m shopping for a company" checkbox', 'wpify-woo' ),
						'value' => 'after_company_checkbox',
					],
					[
						'label' => __( 'After "IC" field', 'wpify-woo' ),
						'value' => 'after_ic_field',
					],
				],
			),
			array(
				'id'    => 'validate_vies',
				'type'  => 'toggle',
				'label' => __( 'Validate VAT number in VIES', 'wpify-woo' ),
				'title' => __( 'Check if want to validate the entered VAT with VIES (IN VAT for SK).', 'wpify-woo' ),
			),
			array(
				'id'    => 'vies_fails',
				'type'  => 'toggle',
				'label' => __( 'Send an order even if it fails validation in VIES', 'wpify-woo' ),
				'title' => __( 'Check if you want to validate the Tax Identification Number immediately after entering and allow the order to be sent even if the validation in VIES fails. If the Tax ID falls under the set countries for zero VAT, then zero VAT will not be applied.',
					'wpify-woo' ),
			),
			array(
				'id'           => 'zero_tax_for_vat_countries',
				'type'         => 'multi_select',
				'label'        => __( 'Zero tax for VAT numbers in', 'wpify-woo' ),
				'desc'         => __( 'Select countries where you want to apply zero VAT.', 'wpify-woo' ),
				'async_params' => [
					'module_id' => $this->id(),
				],
				'options'      => function () {
					return $this->get_eu_countries();
				},
				'multi'        => true,
				'default'      => array(),
			),
		);
	}

	private function get_eu_countries() {
		$countries = Vies::listEuropeanCountries();

		array_walk(
			$countries,
			function ( &$country, $code ) {
				$country = array(
					'label' => $country . ' (' . $code . ')',
					'value' => $code,
				);
			}
		);

		return array_values( $countries );
	}

	/**
	 * Adjust the checkout fields
	 *
	 * @param array $fields Array of the checkout fields.
	 *
	 * @return array
	 */
	public function adjust_checkout_fields( array $fields ): array {
		$fields_default = $this->in_vat_woocommerce_billing( $fields );

		$temp_ic = ! empty( $fields['billing']['billing_ic'] ) && is_array( $fields['billing']['billing_ic'] )
			? $fields['billing']['billing_ic']
			: $fields_default['billing_ic'];
		unset( $fields['billing']['billing_ic'] );

		$temp_dic = ! empty( $fields['billing']['billing_dic'] ) && is_array( $fields['billing']['billing_dic'] )
			? $fields['billing']['billing_dic']
			: $fields_default['billing_dic'];
		unset( $fields['billing']['billing_dic'] );

		$temp_dic_dph = ! empty( $fields['billing']['billing_dic_dph'] ) && is_array( $fields['billing']['billing_dic_dph'] )
			? $fields['billing']['billing_dic_dph']
			: $fields_default['billing_dic_dph'];
		unset( $fields['billing']['billing_dic_dph'] );

		$extra_billing_fields = array();
		$classes              = array( 'form-row-wide' );

		if ( $this->get_setting( 'show_checkbox' ) ) {
			$extra_billing_fields['company_details'] = array(
				'type'     => 'checkbox',
				'label'    => __( 'I\'m shopping for a company', 'wpify-woo' ),
				'required' => false,
				'class'    => array( 'form-row-wide', 'wpify-woo-ic-dic__toggle' ),
				'priority' => $this->get_setting( 'move_vat_fields' ) ? 31 : 200,
			);

			$classes[] = 'wpify-woo-ic-dic__company_field';
		}

		if ( ! empty( $this->get_setting( 'validate_ares' ) ) && in_array( 'ic_entered', $this->get_setting( 'validate_ares' ) ) ) {
			$classes[] = 'wpify-woo-ic--validate';
		}

		if (
			! empty( $this->get_setting( 'validate_vies' ) ) && $this->get_setting( 'validate_vies' ) === true
		) {
			$classes[] = 'wpify-woo-vies--validate';
		}

		if ( $this->get_setting( 'move_company_field' ) && ! empty( $fields['billing']['billing_company'] ) ) {
			$temp_company = $fields['billing']['billing_company'];
			unset( $fields['billing']['billing_company'] );
			$extra_billing_fields['billing_company'] = array_merge(
				$temp_company,
				array(
					'class'    => $classes,
					'priority' => $this->get_setting( 'move_vat_fields' ) ? 32 : ( 'after_ic_field' === $this->get_setting( 'autofill_ares_position' ) ? 214 : 210 ),
				)
			);
		} else {
			$fields['billing']['billing_company']['classes'] = $classes;
		}

		$extra_billing_fields['billing_ic'] = array_merge(
			$temp_ic,
			array(

				'class'    => $classes,
				'priority' => $this->get_setting( 'move_vat_fields' ) ? 33 : 211,
			)
		);

		$extra_billing_fields['billing_dic'] = array_merge(
			$temp_dic,
			array(
				'class'    => $classes,
				'priority' => $this->get_setting( 'move_vat_fields' ) ? 34 : 212,
			)
		);

		$extra_billing_fields['billing_dic_dph'] = array_merge(
			$temp_dic_dph,
			array(
				'class'    => $classes,
				'priority' => $this->get_setting( 'move_vat_fields' ) ? 35 : 213,
			)
		);

		if ( $this->get_setting( 'narrow_vat_fields' ) ) {
			$extra_billing_fields['billing_ic']['class'][0]      = 'form-row-first';
			$extra_billing_fields['billing_dic']['class'][0]     = 'form-row-last';
			$extra_billing_fields['billing_dic_dph']['class'][0] = 'form-row-first';
		}

		$fields['billing'] = array_merge( $fields['billing'], $extra_billing_fields );

		return $fields;
	}

	public function in_vat_woocommerce_billing( $fields = array() ) {
		$fields['billing_ic'] = array(
			'label'       => __( 'Identification no.', 'wpify-woo' ),
			'placeholder' => __( 'Your company\'s identification number', 'wpify-woo' ),
			'required'    => false,
			'type'        => 'text',
		);

		$fields['billing_dic'] = array(
			'label'       => __( 'VAT no.', 'wpify-woo' ),
			'placeholder' => __( 'Your company\'s VAT number', 'wpify-woo' ),
			'required'    => false,
			'type'        => 'text',
		);

		$fields['billing_dic_dph'] = array(
			'label'       => __( 'IN VAT no.', 'wpify-woo' ),
			'placeholder' => __( 'Your company\'s VAT Identification number', 'wpify-woo' ),
			'required'    => false,
			'type'        => 'text',
		);

		return $fields;
	}

	/**
	 * Add editable in vat billing fields in order admin
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function in_vat_woocommerce_billing_admin( array $fields ): array {
		if ( isset( $fields['wpify/company'] ) ) {
			unset( $fields['wpify/company'] );
		}
		if ( isset( $fields['wpify/ic_dic_toggle'] ) ) {
			unset( $fields['wpify/ic_dic_toggle'] );
		}
		if ( isset( $fields['wpify/ic'] ) ) {
			unset( $fields['wpify/ic'] );
		}
		if ( isset( $fields['wpify/dic'] ) ) {
			unset( $fields['wpify/dic'] );
		}
		if ( isset( $fields['wpify/dic-dph'] ) ) {
			unset( $fields['wpify/dic-dph'] );
		}

		$fields['ic'] = array(
			'label'         => __( 'Identification no.', 'wpify-woo' ),
			'show'          => false,
			'wrapper_class' => '',
			'style'         => '',
		);

		$fields['dic'] = array(
			'label'         => __( 'VAT no.', 'wpify-woo' ),
			'show'          => false,
			'wrapper_class' => 'last',
			'style'         => '',
		);

		$fields['dic_dph'] = array(
			'label'         => __( 'IN VAT no.', 'wpify-woo' ),
			'show'          => false,
			'wrapper_class' => '',
			'style'         => '',
		);

		return $fields;
	}

	/**
	 * Add editable in vat billing fields in user profile
	 *
	 * @param $fields
	 *
	 * @return array
	 */
	function in_vat_woocommerce_billing_profile( $fields ): array {
		$fields['billing']['fields']['billing_ic'] = array(
			'label'       => __( 'Identification no.', 'wpify-woo' ),
			'description' => '',
		);

		$fields['billing']['fields']['billing_dic'] = array(
			'label'       => __( 'VAT no.', 'wpify-woo' ),
			'description' => '',
		);

		$fields['billing']['fields']['billing_dic_dph'] = array(
			'label'       => __( 'IN VAT no.', 'wpify-woo' ),
			'description' => '',
		);

		return $fields;
	}

	/**
	 * Adjust checkout fields priorities
	 *
	 * @param array $fields Array of the fields.
	 *
	 * @return mixed
	 */
	public function adjust_fields_priority( array $fields ): array {
		if ( $this->get_setting( 'move_company_field' && ! $this->get_setting( 'move_vat_fields' ) ) ) {
			$fields['company']['priority'] = 210;
		}

		return $fields;
	}

	/**
	 * Add details to localisation address formats
	 *
	 * @param array $address_formats Address formats.
	 *
	 * @return mixed
	 */
	public function localisation_address_formats( array $address_formats ): array {
		if ( $this->woo_integration->is_block_checkout() && is_checkout() || apply_filters( 'wpify_woo_add_ic_dic_to_address', true ) === false ) {
			return $address_formats;
		}

		foreach ( $address_formats as $key => $format ) {
			$address_formats[ $key ] = $format . "\n{billing_ic}\n{billing_dic}\n{billing_dic_dph}";
		}

		return $address_formats;
	}

//	public function display_block_fields_in_admin( $order ) {
//		$billing_ic      = $order->get_meta( '_billing_ic', true );
//		$billing_dic     = $order->get_meta( '_billing_dic', true );
//		$billing_dic_dph = $order->get_meta( '_billing_dic_dph', true );
//
//		echo '<div class="address"><p>';
//		if ( $billing_ic ) {
//			echo '<span>' . __( 'Identification no.', 'wpify-woo' ) . ':</span> ' . esc_html( $billing_ic );
//		}
//		if ( $billing_dic ) {
//			echo '<br><span>' . __( 'VAT no.', 'wpify-woo' ) . ':</span> ' . esc_html( $billing_dic );
//		}
//		if ( $billing_dic_dph ) {
//			echo '<br><span>' . __( 'IN VAT no.', 'wpify-woo' ) . ':</span> ' . esc_html( $billing_dic_dph );
//		}
//		echo '</p></div>';
//	}

	/**
	 * Add the fields values to the address
	 *
	 * @param array    $address Address.
	 * @param WC_Order $order   Order.
	 *
	 * @return array
	 */
	public function add_fields_to_address( array $address, WC_Order $order ): array {
		$address['billing_ic']      = $order->get_meta( '_billing_ic', true );
		$address['billing_dic']     = $order->get_meta( '_billing_dic', true );
		$address['billing_dic_dph'] = $order->get_meta( '_billing_dic_dph', true );

		return $address;
	}

	/**
	 * Replace the tags in emails
	 *
	 * @param array $replacements Array of replacements.
	 * @param array $args         Array of the available args.
	 *
	 * @return array
	 */
	public function replace_tags_in_emails( array $replacements, array $args ): array {
		if ( ! empty( $args['billing_ic'] ) ) {
			$replacements['{billing_ic}'] = sprintf( '%s: %s', __( 'Identification no.', 'wpify-woo' ), $args['billing_ic'] );
		} else {
			$replacements['{billing_ic}'] = '';
		}

		if ( ! empty( $args['billing_dic'] ) ) {
			$replacements['{billing_dic}'] = sprintf( '%s: %s', __( 'VAT no.', 'wpify-woo' ), $args['billing_dic'] );
		} else {
			$replacements['{billing_dic}'] = '';
		}

		if ( ! empty( $args['billing_dic_dph'] ) ) {
			$replacements['{billing_dic_dph}'] = sprintf( '%s: %s', __( 'IN VAT no.', 'wpify-woo' ), $args['billing_dic_dph'] );
		} else {
			$replacements['{billing_dic_dph}'] = '';
		}

		return $replacements;
	}

	/**
	 * Validate the checkout
	 *
	 * @param array $fields Array of the fields.
	 * @param       $errors
	 */
	public function checkout_validation( $fields, $errors ) {
		$country = $_POST['billing_country'];


		if ( $this->get_setting( 'validate_ares' )
			 && $country === 'CZ'
			 && in_array( 'order_submit', $this->get_setting( 'validate_ares' ) )
			 && ! empty( $_POST['billing_ic'] )
		) {
			$ares = ( new Ares\AresFactory() )->create();
			$ic   = sanitize_text_field( $_POST['billing_ic'] );

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

		if ( $this->get_setting( 'validate_vies' ) && $this->get_setting( 'vies_fails' ) !== true ) {
			if ( $country === 'SK' ) {
				$dic_dph = $_POST['billing_dic_dph'] ?? null;
			} else {
				$dic_dph = $_POST['billing_dic'] ?? null;
			}

			if ( ! empty( $dic_dph ) && ! $this->is_valid_dic( $dic_dph ) ) {
				if ( $_POST['billing_country'] === 'SK' ) {
					$errors->add( 'validation', __( 'The entered IN VAT Number has not been found in VIES, please enter valid IN VAT number.', 'wpify-woo' ) );
				} else {
					$errors->add( 'validation', __( 'The entered VAT Number has not been found in VIES, please enter valid VAT number.', 'wpify-woo' ) );
				}
			}
		}

		if ( $this->get_setting( 'validate_format' ) ) {
			if ( ! empty( $_POST['billing_ic'] ) && ! preg_match( '~^\d{8,}$~', $_POST['billing_ic'] ) ) {
				$errors->add( 'validation', __( 'The entered Company Number is not in the required format (8 or more digits without spaces).', 'wpify-woo' ) );
			}

			if ( $country === 'SK' ) {
				if ( ! empty( $_POST['billing_dic'] ) && ! preg_match( '~^\d{10}$~', $_POST['billing_dic'] ) ) {
					$errors->add( 'validation', __( 'The entered VAT Number is not in the required format (10 digits without spaces).', 'wpify-woo' ) );
				}
				if ( ! empty( $_POST['billing_dic_dph'] ) && ! preg_match( '~^SK\d{10}$~', $_POST['billing_dic_dph'] ) ) {
					$errors->add( 'validation', __( 'The entered IN VAT Number is not in the required format (prefix SK + 10 digits without spaces).', 'wpify-woo' ) );
				}
			} elseif (
				in_array( $country, [ 'CZ', 'PL', 'HU', 'DE' ] )
				&&
				! empty( $_POST['billing_dic'] )
				&& ! preg_match( '~^' . $country . '\d{8,10}$~', $_POST['billing_dic'] )
			) {
				$errors->add( 'validation', sprintf( __( 'The entered VAT Number is not in the required format (prefix %s + 8–10 digits without spaces).', 'wpify-woo' ), $country ) );
			}
		}

		if ( $country === 'SK' && ! empty( $_POST['billing_dic_dph'] ) && ! empty( $_POST['billing_dic'] ) ) {
			$dic     = trim( $_POST['billing_dic'] );
			$dic_dph = trim( $_POST['billing_dic_dph'] );

			if ( 'SK' . $dic !== $dic_dph ) {
				$errors->add( 'validation', __( 'The entered VAT Number must be same as IN VAT without SK.', 'wpify-woo' ) );
			}
		}


		$is_required = '</strong> ' . _x( 'is a required field when purchasing for a company.', 'checkout-validation', 'wpify-woo' );

		if ( ! empty( $this->get_setting( 'required_company' ) )
			 && get_option( 'woocommerce_checkout_company_field', 'optional' ) !== 'hidden'
			 && $this->get_setting( 'required_company' )
			 && ! empty( $_POST['company_details'] )
			 && $_POST['company_details'] === '1'
			 && empty( $_POST['billing_company'] )
		) {
			$company_field_label = __( 'Company name', 'woocommerce' ) . $is_required;
			$errors->add( 'validation', '<strong>' . sprintf( _x( 'Billing %s', 'checkout-validation', 'woocommerce' ), $company_field_label ) );
		}

		if ( ( ! empty( $this->get_setting( 'required_ic' ) )
			   && 'if_checkbox' === $this->get_setting( 'required_ic' )
			   && ! empty( $_POST['company_details'] )
			   && $_POST['company_details'] === '1'
			 )
			 || ( ! empty( $this->get_setting( 'required_ic' ) )
				  && 'if_company' === $this->get_setting( 'required_ic' )
				  && ! empty( $_POST['billing_company'] )
			 )
		) {
			if ( empty( $_POST['billing_ic'] ) ) {
				$ic_field_label = __( 'Identification no.', 'wpify-woo' ) . $is_required;
				$errors->add( 'validation', '<strong>' . sprintf( _x( 'Billing %s', 'checkout-validation', 'woocommerce' ), $ic_field_label ) );
			}
		}
	}

	public function is_valid_dic( $dic ) {
		if ( ! empty( WC()->session ) ) {
			$transient = 'wpify_woo_dic_valid_' . $dic;
			$valid     = WC()->session->get( $transient );
		} else {
			$valid = false;
		}

		if ( $valid ) {
			return boolval( $valid );
		}

		if ( empty( $dic ) ) {
			return false;
		}

		$current_country = substr( $dic, 0, 2 );
		$current_vat_no  = substr( $dic, 2 );
		$vies            = new Vies();

		if ( is_numeric( $current_country ) ) {
			return false;
		}

		try {
			if ( $vies->getHeartBeat() ) {
				$response = $vies->validateVat( $current_country, $current_vat_no )->isValid();
				if ( ! empty( WC()->session ) ) {
					WC()->session->set( $transient, $response );
				}

				return $response;
			} else {
				return $vies->validateVatSum( $current_country, $current_vat_no );
			}
		} catch ( Exception $e ) {
			$this->log->error( 'VIES ERROR', array(
				'code'    => $e->getCode(),
				'message' => $e->getMessage(),
			) );

			return false;
		}
	}

	public function add_rest_api() {
		wpify_woo_container()->get( IcDicApi::class );
	}

	public function add_in_vat_to_address( $address, $customer_id, $name ) {
		$address[ $name . '_ic' ]      = get_user_meta( $customer_id, $name . '_ic', true );
		$address[ $name . '_dic' ]     = get_user_meta( $customer_id, $name . '_dic', true );
		$address[ $name . '_dic_dph' ] = get_user_meta( $customer_id, $name . '_dic_dph', true );

		return $address;
	}

	public function set_customer_vat_extempt() {
		// Only run on frontend pages, skip admin and AJAX
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		// Skip if WooCommerce customer is not available
		if ( empty( WC()->customer ) ) {
			return;
		}

		$vies_fails            = $this->get_setting( 'vies_fails' );
		$vat_extempt_countries = $this->get_setting( 'zero_tax_for_vat_countries' );

		// Skip if VAT exempt functionality is not configured
		if ( empty( $vat_extempt_countries ) ) {
			return;
		}

		$billing_country = WC()->customer->get_billing_country();
		$dic             = $billing_country === 'SK'
			? WC()->customer->get_meta( 'billing_dic_dph' )
			: WC()->customer->get_meta( 'billing_dic' );

		// Create cache key based on current data
		$cache_key = 'vat_exempt_' . md5( $billing_country . '_' . $dic . '_' . WC()->customer->get_shipping_country() );

		// Check if we already calculated this recently (cache for current session)
		$cached_result = WC()->session->get( $cache_key );
		$cache_time    = WC()->session->get( $cache_key . '_time' );

		// Use cache if it's less than 5 minutes old
//		if ( $cached_result !== null && $cache_time && ( time() - $cache_time ) < 300 ) {
//			WC()->customer->set_is_vat_exempt( $cached_result );
//
//			return;
//		}

		// Calculate VAT exempt status
		$is_vat_extempt = false;

		if ( ! empty( $dic ) ) {
			// If VIES fails is enabled and DIC is not valid, set to false
			if ( ! empty( $vies_fails ) && $vies_fails === true && ! $this->is_valid_dic( $dic ) ) {
				$is_vat_extempt = false;
			} else {
				$shipping_country = WC()->customer->get_shipping_country() ?: $billing_country;
				$is_vat_extempt   = $this->is_vat_extempt( $dic, $shipping_country );
			}
		}

		// Cache the result
		WC()->session->set( $cache_key, $is_vat_extempt );
		WC()->session->set( $cache_key . '_time', time() );

		// Set customer VAT exempt status
		WC()->customer->set_is_vat_exempt( $is_vat_extempt );
	}

	public function log_order_vat_exempt_decision( $order_id, $posted_data, $order ) {
		// Only log if DIC was provided
		$billing_country = $order->get_billing_country();
		$dic             = $billing_country === 'SK'
			? $order->get_meta( '_billing_dic_dph' )
			: $order->get_meta( '_billing_dic' );

		if ( empty( $dic ) ) {
			return; // No DIC provided, skip logging
		}

		// Detect VAT exempt from order - if tax_total is 0 but order has taxable items, likely VAT exempt
		$customer_vat_exempt  = ( $order->get_total_tax() == 0 && $order->get_total() > 0 );
		$vat_exempt_countries = $this->get_setting( 'zero_tax_for_vat_countries' );
		$shop_country         = wc_get_base_location()['country'];
		$shipping_country     = $order->get_shipping_country();

		// Determine if VAT should be exempt based on current logic
		$should_be_vat_exempt = false;
		if ( ! empty( $vat_exempt_countries ) ) {
			$should_be_vat_exempt = $this->is_vat_extempt( $dic, $shipping_country );
		}

		$this->log->info( 'Order VAT Exempt Decision', [
			'order_id'             => $order->get_id(),
			'order_number'         => $order->get_order_number(),
			'billing_country'      => $billing_country,
			'shipping_country'     => $shipping_country,
			'shop_country'         => $shop_country,
			'submitted_dic'        => $dic,
			'validate_in_VIES'     => $this->get_setting( 'validate_vies' ),
			'customer_vat_exempt'  => $customer_vat_exempt,
			'should_be_vat_exempt' => $should_be_vat_exempt,
			'vat_exempt_countries' => $vat_exempt_countries,
			'order_total'          => $order->get_total(),
			'tax_total'            => $order->get_total_tax(),
			'context'              => 'Order created - Classic checkout'
		] );
	}

	/**
	 * @param $dic
	 *
	 * @return bool
	 */
	public function is_vat_extempt( $dic, $shipping_country = '' ): bool {
		if ( ! $dic ) {
			return false;
		}

		$current_country = substr( $dic, 0, 2 );

		// Check applicability FIRST - if not applicable, don't waste time on VIES validation
		$is_applicable = $this->is_vat_extempt_applicable( $current_country, $shipping_country );
		if ( ! $is_applicable ) {
			return false; // Skip expensive VIES validation if result would be false anyway
		}

		$current_vat_no = substr( $dic, 2 );
		$vies           = new Vies();
		$is_valid       = false;

		try {
			if ( $this->get_setting( 'validate_vies' ) && $vies->getHeartBeat() ) {
				$is_valid = $vies->validateVat( $current_country, $current_vat_no )->isValid();
			} else {
				$is_valid = $vies->validateVatSum( $current_country, $current_vat_no );
			}
		} catch ( ViesException|ViesServiceException $e ) {
			$is_valid = false;
		}

		return $is_valid;
	}

	public function is_vat_extempt_applicable( $billing_country, $shipping_country = '' ) {
		$vat_extempt_countries = $this->get_setting( 'zero_tax_for_vat_countries' );
		$shop_country          = wc_get_base_location()['country'];

		if ( ! $shipping_country ) {
			$shipping_country = ! empty( WC()->customer ) && WC()->customer->get_shipping_country() ? WC()->customer->get_shipping_country() : $billing_country;
		}

		if ( $billing_country === $shop_country ) {
			return false;
		}

		return $shipping_country != $shop_country && in_array( $shipping_country, $vat_extempt_countries );
	}

	public function set_vat_extempt_on_order_review( $strdata ) {
		$data                  = array();
		$vies_fails            = $this->get_setting( 'vies_fails' );
		$vat_extempt_countries = $this->get_setting( 'zero_tax_for_vat_countries' );

		if ( empty( $vat_extempt_countries ) ) {
			return;
		}

		wp_parse_str( $strdata, $data );

		$country = $data['billing_country'] ?? '';
		$dic_dph = $country === 'SK'
			? ( $data['billing_dic_dph'] ?? '' )
			: ( $data['billing_dic'] ?? '' );

		// Determine shipping country - use billing country if ship to different address is not checked
		// or if shipping country is not provided (when "ship to same address" is checked)
		$ship_to_different = isset( $data['ship_to_different_address'] ) && $data['ship_to_different_address'] === '1';
		$shipping_country  = $ship_to_different && ! empty( $data['shipping_country'] )
			? $data['shipping_country']
			: $country;

		if ( ! empty( $vies_fails ) && $vies_fails === true && ! empty( $dic_dph ) && ! $this->is_valid_dic( $dic_dph ) ) {
			WC()->customer->set_is_vat_exempt( false );

			return;
		}

		if ( ! empty( $dic_dph )
			 && (
				 isset( $data['company_details'] ) && $data['company_details'] === '1'
				 ||
				 ! isset( $data['company_details'] )
			 )
		) {
			$vat_exempt_result = $this->is_vat_extempt( $dic_dph, $shipping_country );
			WC()->customer->set_is_vat_exempt( $vat_exempt_result );
		} else {
			WC()->customer->set_is_vat_exempt( false );
		}
	}

	public function add_ares_autofill_to_company_field( $field, $key ) {
		if ( 'company_details' === $key ) {
			ob_start(); ?>
			<div class="form-row">
				<?php $this->render_ares(); ?>
			</div>
			<?php
			$field = $field . ob_get_clean();
		}

		return $field;
	}

	public function add_ares_autofill_to_ic_field( $field, $key ) {
		if ( 'billing_ic' === $key ) {
			ob_start(); ?>
			<style type="text/css">
				#wpify-woo-icdic__ares-autofill-button, #ares_in {
					display: none;
				}

				.wpify-woo-icdic__ares-autofill {
					display: block;
				}
			</style>
			<div class="form-row wpify-woo-ic-dic__company_field">
				<?php $this->render_ares(); ?>
			</div>
			<?php
			$field = $field . ob_get_clean();
		}

		return $field;
	}


	public function render_ares() {
		if ( ! $this->get_setting( 'autofill_ares' ) ) {
			return;
		} ?>
		<div id="wpify-woo-ares-autofill">
			<a href="#"
			   id="wpify-woo-icdic__ares-autofill-button"><?php echo $this->get_setting( 'autofill_ares_text' ); ?></a>
			<div class="wpify-woo-icdic__ares-autofill">
				<input type="text" name="ares_vat_no" id="ares_in"
					   placeholder="<?php _e( 'Identification number', 'wpify-woo' ); ?>"/>
				<input type="button"
					   value="<?php echo $this->get_setting( 'submit_ares_text' ) ?: __( 'Search in ARES', 'wpify-woo' ); ?>"
					   id="wpify-woo-icdic__ares-submit"/>
				<div id="wpify-woo-icdic__ares-result"></div>
			</div>
		</div>
		<?php
	}

	public function autofill_vat_fields_in_admin( $data, $customer, $user_id ) {
		$data['billing']['ic']      = get_user_meta( $user_id, 'billing_ic', true );
		$data['billing']['dic']     = get_user_meta( $user_id, 'billing_dic', true );
		$data['billing']['dic_dph'] = get_user_meta( $user_id, 'billing_dic_dph', true );

		return $data;
	}

	public function add_post_class( $classes, $class, $post_id ) {
		if ( get_post_type( $post_id ) === 'shop_order' ) {
			$order                 = wc_get_order( $post_id );
			$total_tax             = $order->get_total_tax();
			$billing_country       = $order->get_billing_country();
			$shipping_country      = $order->get_shipping_country() ?: $billing_country;
			$vat_extempt_countries = $this->get_setting( 'zero_tax_for_vat_countries' );

			if ( $order->get_billing_country() === 'SK' ) {
				$dic = $order->get_meta( '_billing_dic_dph', true );
			} else {
				$dic = $order->get_meta( '_billing_dic', true );
			}

			if ( ! empty( $vat_extempt_countries ) && $this->is_vat_extempt_applicable( $billing_country, $shipping_country ) && $total_tax === 0.0 && ! empty( $dic ) ) {
				$classes[] = 'vat-exempt';
			}
		}

		return $classes;
	}


}
