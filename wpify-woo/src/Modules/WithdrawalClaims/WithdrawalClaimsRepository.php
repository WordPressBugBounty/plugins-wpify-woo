<?php

namespace WpifyWoo\Modules\WithdrawalClaims;

defined( 'ABSPATH' ) || exit;

use WpifyWooDeps\Wpify\Model\CustomTableRepository;

/**
 * @method WithdrawalClaimsModel[] find( array $args = array() )
 * @method WithdrawalClaimsModel create()
 */
class WithdrawalClaimsRepository extends CustomTableRepository {
	public function table_name(): string {
		return 'wpify_woo_requests';
	}

	public function model(): string {
		return WithdrawalClaimsModel::class;
	}

	/**
	 * Get all requests for a given order, newest first.
	 *
	 * @return WithdrawalClaimsModel[]
	 */
	public function find_by_order( int $order_id ): array {
		return $this->find( array(
			'where'    => array(
				'order_id' => $order_id,
			),
			'order_by' => 'submitted_at DESC',
		) );
	}

	/**
	 * Count requests for an order — used by Vrstva A spam protection (max_requests_per_order).
	 */
	public function count_by_order( int $order_id ): int {
		return count( $this->find_by_order( $order_id ) );
	}

	/**
	 * Get the most recent submission for an order — used by Vrstva A (min_seconds_between_requests).
	 */
	public function find_last_by_order( int $order_id ): ?WithdrawalClaimsModel {
		$items = $this->find( array(
			'where'    => array(
				'order_id' => $order_id,
			),
			'order_by' => 'submitted_at DESC',
			'limit'    => 1,
		) );

		return $items[0] ?? null;
	}
}
