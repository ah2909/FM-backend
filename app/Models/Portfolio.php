<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Portfolio
 * 
 * @property int $id
 * @property string $name
 * @property string $description
 * @property int $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property User $user
 * @property Collection|Asset[] $assets
 * @property Collection|Transaction[] $transactions
 *
 * @package App\Models
 */
class Portfolio extends Model
{
	protected $table = 'portfolios';

	protected $dateFormat = 'Y-m-d H:i:s';
	protected $casts = [
		'user_id' => 'int',
		'share_amounts' => 'bool'
	];

	protected $fillable = [
		'name',
		'description',
		'user_id',
		'last_updated',
		'share_token',
		'share_amounts'
	];
	

	public function assets()
	{
		return $this->belongsToMany(Asset::class, 'portfolio_asset')
					->withPivot('amount', 'avg_price')
					->withTimestamps();
	}

	public function transactions()
	{
		return $this->hasMany(Transaction::class);
	}
}
