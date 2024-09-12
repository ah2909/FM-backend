<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Asset extends Model
{
    use HasFactory, HasApiTokens;

    protected $table = 'assets';
    protected $fillable = [
        'id',
        'name',
        'img_url',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_asset');
    }
}
