<?php

namespace WpifyWoo\Modules\WithdrawalClaims\Emails;

defined( 'ABSPATH' ) || exit;

use WC_Email;
use WC_Order;
use WpifyWoo\Modules\WithdrawalClaims\WithdrawalClaimsModel;
use WpifyWoo\Modules\WithdrawalClaims\WithdrawalClaimsRepository;
use WpifyWooDeps\Wpify\PluginUtils\PluginUtils;

/**
 * Shared base for withdrawal/claim notification e-mails.
 *
 * Each concrete subclass sets:
 *   - $this->id
 *   - $this->customer_email (true/false)
 *   - $this->title / description / template paths
 *   - request_type via type() method
 */
abstract class AbstractRequestEmail extends WC_Email {

	protected WithdrawalClaimsRepository $repository;
	protected PluginUtils $utils;

	/** @var WithdrawalClaimsModel|null */
	protected $request;

	public function __construct( WithdrawalClaimsRepository $repository, PluginUtils $utils ) {
		$this->repository = $repository;
		$this->utils      = $utils;

		$this->template_base = $this->utils->get_plugin_path( 'src/Modules/WithdrawalClaims/templates/' );

		parent::__construct();
	}

	/**
	 * Whether this email is for the customer or admin.
	 */
	abstract public function is_customer(): bool;

	/**
	 * Request type — 'withdrawal' or 'claim'.
	 */
	abstract public function type(): string;

	/**
	 * Trigger e-mail by request id.
	 */
	public function trigger( int $request_id ): void {
		$requests = $this->repository->find( array( 'where' => array( 'id' => $request_id ) ) );
		$request  = $requests[0] ?? null;

		if ( ! $request instanceof WithdrawalClaimsModel ) {
			return;
		}

		if ( $request->request_type !== $this->type() ) {
			return;
		}

		$order = wc_get_order( $request->order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->request = $request;
		$this->object  = $order;

		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );

		if ( $this->is_customer() ) {
			// Customer emails always go to billing email — see 5.10 (data-leak prevention).
			$recipient = $order->get_billing_email();
		} else {
			// Admin emails go to admin recipient configured in form fields.
			$recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
		}

		$recipient = apply_filters(
			'wpify_woo_withdrawal_claims_email_recipient',
			$recipient,
			$request_id,
			$this->is_customer() ? 'customer' : 'admin'
		);

		$this->recipient = $recipient;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		do_action( 'wpify_woo_withdrawal_claims_before_email_send', $this, $request_id );

		$success = $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);

		do_action( 'wpify_woo_withdrawal_claims_after_email_send', $this, $request_id, (bool) $success );
	}

	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			$this->common_template_args( false ),
			'',
			$this->template_base
		);
	}

	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			$this->common_template_args( true ),
			'',
			$this->template_base
		);
	}

	private function common_template_args( bool $plain_text ): array {
		$submitted_ts = $this->request && ! empty( $this->request->submitted_at )
			? strtotime( $this->request->submitted_at )
			: 0;
		$period_ts    = $this->request && ! empty( $this->request->period_end )
			? strtotime( $this->request->period_end )
			: 0;

		$datetime_format = wc_date_format() . ' ' . wc_time_format();

		return array(
			'email_heading'          => $this->get_heading(),
			'sent_to_admin'          => ! $this->is_customer(),
			'plain_text'             => $plain_text,
			'email'                  => $this,
			'order'                  => $this->object,
			'request'                => $this->request,
			'request_items'          => $this->decode_items(),
			'additional_content'     => $this->get_additional_content(),
			'submitted_at_formatted' => $submitted_ts ? wp_date( $datetime_format, $submitted_ts ) : '',
			'period_end_formatted'   => $period_ts ? wp_date( $datetime_format, $period_ts ) : '',
		);
	}

	/**
	 * Decode items_json into a normalized array, enriched with current order_item data.
	 */
	protected function decode_items(): array {
		if ( ! $this->request || ! $this->object instanceof WC_Order ) {
			return array();
		}

		$decoded = json_decode( $this->request->items_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$result = array();
		foreach ( $decoded as $row ) {
			$line_item_id = (int) ( $row['line_item_id'] ?? 0 );
			$quantity     = (int) ( $row['quantity'] ?? 0 );
			$item         = $this->object->get_item( $line_item_id );

			if ( $item ) {
				$result[] = array(
					'line_item_id' => $line_item_id,
					'quantity'     => $quantity,
					'name'         => $item->get_name(),
					'item'         => $item,
				);
			} else {
				/* translators: %d: line item id */
				$result[] = array(
					'line_item_id' => $line_item_id,
					'quantity'     => $quantity,
					'name'         => sprintf( __( 'Item no longer in order (line #%d)', 'wpify-woo' ), $line_item_id ),
					'item'         => null,
				);
			}
		}

		return $result;
	}

	public function init_form_fields() {
		$fields = array(
			'enabled'    => array(
				'title'   => __( 'Enable/Disable', 'wpify-woo' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'wpify-woo' ),
				'default' => 'yes',
			),
			'subject'    => array(
				'title'       => __( 'Subject', 'wpify-woo' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Default: %s', 'wpify-woo' ), $this->get_default_subject() ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'    => array(
				'title'       => __( 'Email heading', 'wpify-woo' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Default: %s', 'wpify-woo' ), $this->get_default_heading() ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'wpify-woo' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'wpify-woo' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'wpify-woo' ),
				'description' => __( 'Text shown below the mandatory request details. Use it for next-steps instructions (e.g. "Reply to this email with photos of the defect").', 'wpify-woo' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'wpify-woo' ),
				'type'        => 'textarea',
				'default'     => $this->get_default_additional_content(),
				'desc_tip'    => true,
			),
		);

		// Admin emails have a recipient field
		if ( ! $this->is_customer() ) {
			$fields = array_merge(
				array(
					'recipient' => array(
						'title'       => __( 'Recipient(s)', 'wpify-woo' ),
						'type'        => 'text',
						'description' => sprintf( __( 'Comma-separated emails. Defaults to %s.', 'wpify-woo' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
						'placeholder' => '',
						'default'     => '',
						'desc_tip'    => true,
					),
				),
				$fields
			);
		}

		$this->form_fields = $fields;
	}

	public function get_default_additional_content(): string {
		if ( $this->is_customer() ) {
			return __( 'You will be contacted by our team regarding the next steps. If you need to provide additional information (for example photos of a defect for a claim), please reply directly to this email.', 'wpify-woo' );
		}

		return __( 'A new request was submitted. Review the details above and process accordingly.', 'wpify-woo' );
	}
}
