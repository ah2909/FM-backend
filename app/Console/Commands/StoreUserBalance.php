<?php

namespace App\Console\Commands;

use App\Models\Portfolio;
use App\Services\PortfolioService;
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

    protected $portfolioService;

    public function __construct(PortfolioService $portfolioService)
    {
        parent::__construct();
        $this->portfolioService = $portfolioService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $portfolios = Portfolio::all();

        foreach ($portfolios as $portfolio) {
           $portfolio->assets = $portfolio->assets->map(function($asset) {
            if (isset($asset->pivot->amount)) {
                $asset->amount = $asset->pivot->amount;
                $asset->avg_price = $asset->pivot->avg_price;
                unset($asset->pivot);
            }
            return $asset;
            });
            $priceData = $this->portfolioService->getPriceOfPort($portfolio->assets);
            $portfolio = $this->portfolioService->calculatePortfolioValue($portfolio, $priceData);
            
            // Store the balance in the database
            DB::table('portfolio_balance')->insert([
                'balance' => $portfolio->totalValue,
                'portfolio_id' => $portfolio->id,
                'user_id' => $portfolio->user_id,
                'date' => now(),
            ]);
        }

        $this->info('Balance of portfolios have been stored successfully.');
        
    }
}
