<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Binance extends Model
{
    use HasFactory, HasApiTokens;
    protected $table = 'binance';
    protected $fillable = [
        'id',
        'api_key',
        'secret_key',
        'user_id',
    ];

}
