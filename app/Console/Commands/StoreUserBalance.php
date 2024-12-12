<?php

namespace App\Console\Commands;

use App\Http\Controllers\BinanceController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StoreUserBalance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:store-user-balance';
    
    protected $description = 'Store the balance of each user to the database';

    protected $binanceController;

    public function __construct(BinanceController $binanceController)
    {
        parent::__construct();
        $this->binanceController = $binanceController;
    }

    public function handle()
    {
	$users = User::all();

	foreach ($users as $user) {
    		// Assuming you have a method to get the balance
    		$balance = $this->binanceController->getAssetDetails($user->id);
    		if(!$balance) continue;
    		$data = $balance->getData();
            if(!property_exists($data, 'total')) continue;
    		// Store the balance in the database
    		DB::table('cron_data')->insert([
        		'asset_balance' => $data->total,
        		'user_id' => $user->id,
        		'created_at' => now(),
        		'updated_at' => now(),
    		]);
	}

        $this->info('User balances have been stored successfully.');
    }
}
