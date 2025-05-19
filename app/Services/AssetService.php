<?php

namespace App\Services;

use App\Models\Asset;

class AssetService
{
    public function checkAssetExists($asset)
    {
        // Check if the asset exists in the database
        return Asset::where('symbol', strtolower($asset))->first();
    }
}