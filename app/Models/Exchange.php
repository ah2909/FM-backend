<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Exchange
 * 
 * @property int $id
 * @property string $name
 * @property string $api_key
 * @property string $secret_key
 * @property int $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property User $user
 * @property Collection|Transaction[] $transactions
 *
 * @package App\Models
 */
class Exchange extends Model
{
	protected $table = 'exchanges';

	protected $casts = [
		'user_id' => 'int',
		'cex_id' => 'int'
	];

	protected $fillable = [
		'cex_id',
		'api_key',
		'secret_key',
		'user_id'
	];

	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function transactions()
	{
		return $this->hasMany(Transaction::class);
	}
}
