<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PortfolioAsset extends Pivot
{
    protected $table = 'portfolio_asset';

    public $incrementing = true;

    public function wallets()
    {
        return $this->hasMany(PortfolioAssetWallet::class, 'portfolio_asset_id');
    }
}
