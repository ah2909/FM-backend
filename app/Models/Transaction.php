<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Transaction extends Model
{
    use HasFactory, HasApiTokens;

    protected $table = 'transactions';
    protected $dateFormat = 'd/m/Y H:i';
    protected $fillable = [
        'content',
        'amount',
        'type',
        'user_id',
        'category_id',
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function category() {
        return $this->belongsTo(Category::class);
    }
}
