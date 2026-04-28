<?php
/**
 * Customer withdrawal/claim email — plain text.
 *
 * @var string $email_heading
 * @var \WC_Email $email
 * @var \WC_Order|null $order
 * @var \WpifyWoo\Modules\WithdrawalClaims\WithdrawalClaimsModel|null $request
 * @var array $request_items
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( $email_heading ) . " =\n\n";

if ( $request && $order ) {
	echo esc_html__( 'We have received your request. This email confirms its receipt as required by Directive (EU) 2023/2673 — keep it as a record.', 'wpify-woo' ) . "\n\n";

	echo esc_html__( 'Type', 'wpify-woo' ) . ': ' . esc_html( $request->request_type ) . "\n";
	echo esc_html__( 'Submitted at', 'wpify-woo' ) . ': ' . esc_html( $submitted_at_formatted ) . "\n";
	echo esc_html__( 'Order number', 'wpify-woo' ) . ': ' . esc_html( $request->order_number ) . "\n";
	echo esc_html__( 'Customer', 'wpify-woo' ) . ': ' . esc_html( $request->customer_name . ' <' . $request->customer_email . '>' ) . "\n";

	if ( ! empty( $request->reason ) ) {
		echo "\n" . esc_html__( 'Reason / description', 'wpify-woo' ) . ":\n";
		echo esc_html( $request->reason ) . "\n";
	}

	if ( ! empty( $request_items ) ) {
		echo "\n" . esc_html__( 'Items', 'wpify-woo' ) . ":\n";
		foreach ( $request_items as $row ) {
			echo '- ' . esc_html( $row['name'] ) . ' × ' . (int) $row['quantity'] . "\n";
		}
	}
}

if ( ! empty( $additional_content ) ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n" . esc_html( wp_strip_all_tags( get_option( 'blogname' ) ) );
