<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Asset
 * 
 * @property int $id
 * @property string $symbol
 * @property string $name
 * @property string $img_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Collection|Portfolio[] $portfolios
 * @property Collection|Transaction[] $transactions
 *
 * @package App\Models
 */
class Asset extends Model
{
	protected $table = 'assets';

	protected $fillable = [
		'symbol',
		'name',
		'img_url'
	];

	public function portfolios()
	{
		return $this->belongsToMany(Portfolio::class, 'portfolio_asset')
					->withTimestamps();
	}

	public function transactions()
	{
		return $this->hasMany(Transaction::class);
	}
}
