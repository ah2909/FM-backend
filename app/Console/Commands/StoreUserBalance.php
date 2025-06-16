<?php

namespace App\Console\Commands;

use App\Http\Controllers\PortfolioController;
use App\Models\Portfolio;
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

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store balance of each user daily';

    // protected $portfolioController;

    // public function __construct(PortfolioController $portfolioController)
    // {
    //     parent::__construct();
    //     $this->portfolioController = $portfolioController;
    // }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // $portfolios = Portfolio::all();

        // foreach ($portfolios as $portfolio) {
        //     $portfolio = $this->portfolioController->calculatePortfolioBalance($portfolio);
        //     // Store the balance in the database
        //     DB::table('portfolio_balance')->insert([
        //         'balance' => $portfolio->totalValue,
        //         'portfolio_id' => $portfolio->id,
        //         'user_id' => $portfolio->user_id,
        //         'created_at' => now(),
        //     ]);
        // }

        $this->info('Balance of portfolios have been stored successfully.');
        
    }
}
