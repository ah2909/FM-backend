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
		'user_id' => 'int'
	];

	protected $fillable = [
		'name',
		'description',
		'user_id'
	];
	
	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function assets()
	{
		return $this->belongsToMany(Asset::class, 'portfolio_asset')
					->withPivot('amount')
					->withTimestamps();
	}

	public function transactions()
	{
		return $this->hasMany(Transaction::class);
	}
}
