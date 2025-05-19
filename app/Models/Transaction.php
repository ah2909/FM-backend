<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Transaction
 * 
 * @property int $id
 * @property int $exchange_id
 * @property int $portfolio_id
 * @property int $asset_id
 * @property float $quantity
 * @property float $price
 * @property string $type
 * @property Carbon|null $transact_date
 * @property Carbon $updated_at
 * @property Carbon $created_at
 * 
 * @property Asset $asset
 * @property Exchange $exchange
 * @property Portfolio $portfolio
 *
 * @package App\Models
 */
class Transaction extends Model
{
	protected $table = 'transactions';

	protected $casts = [
		'exchange_id' => 'int',
		'portfolio_id' => 'int',
		'asset_id' => 'int',
		'quantity' => 'float',
		'price' => 'float',
		'transact_date' => 'datetime'
	];

	protected $fillable = [
		'exchange_id',
		'portfolio_id',
		'asset_id',
		'quantity',
		'price',
		'type',
		'transact_date'
	];

	public function asset()
	{
		return $this->belongsTo(Asset::class);
	}

	public function portfolio()
	{
		return $this->belongsTo(Portfolio::class);
	}
}
