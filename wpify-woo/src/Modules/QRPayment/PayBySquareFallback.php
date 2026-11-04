<?php

namespace WpifyWoo\Modules\QRPayment;

defined( 'ABSPATH' ) || exit;

use WpifyWooDeps\rikudou\SkQrPayment\Exception\QrPaymentException;
use WpifyWooDeps\rikudou\SkQrPayment\Iban\IbanBicPair;
use WpifyWooDeps\rikudou\SkQrPayment\QrPayment;
use WpifyWooDeps\Rikudou\QrPaymentQrCodeProvider\QrCodeProviderLocator;

/**
 * PAY by square generation for hosts without the xz binary.
 *
 * Builds the same payload as rikudou/skqrpayment QrPayment::getQrString(), but compresses it
 * with a literal-only LZMA1 encoder (lc=3, lp=0, pb=2) in pure PHP instead of calling xz.
 * The stream is valid LZMA, so any decoder reads it; on payment-sized data it is as long as xz output.
 */
class PayBySquareFallback {
	private int $low = 0;
	private int $range = 0xFFFFFFFF;
	private int $cache = 0;
	private int $cache_size = 1;
	private string $output = '';

	/**
	 * Whether the library can run xz on this host.
	 *
	 * @param QrPayment $payment Configured payment (may carry a custom xz path from the options filter)
	 *
	 * @return bool
	 */
	public static function is_xz_available( QrPayment $payment ): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		try {
			$payment->getXzBinary();
		} catch ( \Throwable $e ) {
			// The locator throws when xz is missing, or errors when exec() is disabled.
			return false;
		}

		return true;
	}

	/**
	 * QR code image as data URI, rendered by the same provider the library uses.
	 *
	 * @param QrPayment $payment Configured payment
	 *
	 * @return string
	 */
	public static function get_data_uri( QrPayment $payment ): string {
		return ( new QrCodeProviderLocator() )->getProvider()->getQrCode( self::get_qr_string( $payment ) )->getDataUri();
	}

	/**
	 * PAY by square string, field for field as QrPayment::getQrString().
	 *
	 * @param QrPayment $payment Configured payment
	 *
	 * @return string
	 */
	public static function get_qr_string( QrPayment $payment ): string {
		$ibans = $payment->getIbans();

		if ( ! count( $ibans ) ) {
			throw new QrPaymentException( 'Cannot generate QR payment with no IBANs' );
		}

		$fields = array(
			true,
			round( $payment->getAmount(), 2 ),
			$payment->getCurrency(),
			$payment->getDueDate()->format( 'Ymd' ),
			$payment->getVariableSymbol(),
			$payment->getConstantSymbol(),
			$payment->getSpecificSymbol(),
			'',
			$payment->getComment(),
			count( $ibans ),
		);

		foreach ( $ibans as $iban ) {
			$pair     = $iban instanceof IbanBicPair ? $iban : new IbanBicPair( $iban );
			$fields[] = $pair->getIban()->asString();
			$fields[] = $pair->getBic();
		}

		$fields[] = 0; // standing order
		$fields[] = 0; // direct debit
		$fields[] = $payment->getPayeeName();
		$fields[] = $payment->getPayeeAddressLine1();
		$fields[] = $payment->getPayeeAddressLine2();

		$data = implode( "\t", array( $payment->getInternalId(), '1', implode( "\t", $fields ) ) );
		$data = strrev( hash( 'crc32b', $data, true ) ) . $data;

		// Header: document type and version (2 bytes), then the uncompressed length.
		$binary = "\x00\x00" . pack( 'v', strlen( $data ) ) . ( new self() )->compress( $data );

		$bits = '';
		foreach ( str_split( $binary ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$bits .= str_repeat( '0', ( 5 - strlen( $bits ) % 5 ) % 5 );

		$result = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$result .= '0123456789ABCDEFGHIJKLMNOPQRSTUV'[ bindec( $chunk ) ];
		}

		return $result;
	}

	/**
	 * Literal-only LZMA1 stream (lc=3, lp=0, pb=2): every byte is coded as a literal, no matches.
	 *
	 * @param string $data Data to compress
	 *
	 * @return string
	 */
	private function compress( string $data ): string {
		$is_match = array_fill( 0, 4, 1024 );
		$literals = array_fill( 0, 8, array_fill( 0, 0x300, 1024 ) );
		$previous = 0;

		for ( $i = 0, $length = strlen( $data ); $i < $length; $i++ ) {
			$byte = ord( $data[ $i ] );

			// The state stays 0 after literals, so isMatch is indexed by posState only.
			$this->encode_bit( $is_match, $i & 3, 0 );

			$probs   = &$literals[ $previous >> 5 ];
			$context = 1;
			for ( $bit_index = 7; $bit_index >= 0; $bit_index-- ) {
				$bit = ( $byte >> $bit_index ) & 1;
				$this->encode_bit( $probs, $context, $bit );
				$context = ( $context << 1 ) | $bit;
			}
			unset( $probs );

			$previous = $byte;
		}

		for ( $i = 0; $i < 5; $i++ ) {
			$this->shift_low();
		}

		return $this->output;
	}

	private function encode_bit( array &$probs, int $index, int $bit ): void {
		$bound = ( $this->range >> 11 ) * $probs[ $index ];

		if ( 0 === $bit ) {
			$this->range      = $bound;
			$probs[ $index ] += ( 2048 - $probs[ $index ] ) >> 5;
		} else {
			$this->low       += $bound;
			$this->range     -= $bound;
			$probs[ $index ] -= $probs[ $index ] >> 5;
		}

		while ( $this->range < 0x01000000 ) {
			$this->range = ( $this->range << 8 ) & 0xFFFFFFFF;
			$this->shift_low();
		}
	}

	private function shift_low(): void {
		if ( $this->low < 0xFF000000 || $this->low > 0xFFFFFFFF ) {
			$carry = $this->low >> 32;
			$temp  = $this->cache;
			do {
				$this->output .= chr( ( $temp + $carry ) & 0xFF );
				$temp          = 0xFF;
			} while ( --$this->cache_size !== 0 );
			$this->cache = ( $this->low >> 24 ) & 0xFF;
		}

		$this->cache_size++;
		$this->low = ( $this->low & 0x00FFFFFF ) << 8;
	}
}
