<?php

namespace WpifyWoo\Modules\WithdrawalClaims;

defined( 'ABSPATH' ) || exit;

use WpifyWooDeps\Wpify\Model\Attributes\Column;
use WpifyWooDeps\Wpify\Model\Model;

class WithdrawalClaimsModel extends Model {
	#[Column( type: Column::INT, auto_increment: true, primary_key: true )]
	public int $id;

	#[Column( type: Column::VARCHAR )]
	public string $request_type;

	#[Column( type: Column::BIGINT )]
	public int $order_id;

	#[Column( type: Column::VARCHAR )]
	public string $order_number;

	#[Column( type: Column::VARCHAR )]
	public string $customer_email;

	#[Column( type: Column::VARCHAR )]
	public string $customer_name;

	#[Column( type: Column::TEXT )]
	public string $items_json;

	#[Column( type: Column::TEXT )]
	public string $reason;

	#[Column( type: Column::VARCHAR )]
	public string $scope;

	#[Column( type: Column::VARCHAR )]
	public string $status;

	#[Column( type: Column::VARCHAR )]
	public string $period_end;

	#[Column( type: Column::VARCHAR )]
	public string $submitted_at;

	#[Column( type: Column::VARCHAR )]
	public string $customer_ip;

	#[Column( type: Column::VARCHAR )]
	public string $customer_user_agent;

	#[Column( type: Column::VARCHAR )]
	public string $created_at;
}
