<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CEXSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            ['id' => 1, 'name' => 'Binance', 'img_url' => null],
            ['id' => 2, 'name' => 'OKX', 'img_url' => null],
            ['id' => 3, 'name' => 'Bybit', 'img_url' => null],
        ];

        DB::table('CEXs')->insert($data);
    }
}
