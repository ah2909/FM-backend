<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioAssetWallet extends Model
{
    protected $table = 'portfolio_asset_wallet';

    protected $casts = [
        'portfolio_asset_id' => 'int',
        'amount' => 'float',
    ];

    protected $fillable = [
        'portfolio_asset_id',
        'exchange',
        'wallet_type',
        'amount',
    ];
}
