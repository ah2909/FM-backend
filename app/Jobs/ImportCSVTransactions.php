<?php

namespace App\Jobs;

use App\Models\Portfolio;
use App\Models\Transaction;
use App\Services\PortfolioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportCSVTransactions implements ShouldQueue
{
    use Queueable;

    protected $file;
    protected $userId;
    protected $exchangeId;

    /**
     * Create a new job instance.
     */
    public function __construct($file, $userId, $exchangeId)
    {
        $this->file = $file;
        $this->userId = $userId;
        $this->exchangeId = $exchangeId;
    }

    private function formatOkxCSVData($csvContents, $portfolio, $excludedFeeUnits, $listId)
    {
        $skipHeader = true;
        $transactions = [];
        $setId = [];
        
        foreach ($csvContents as $content) {
            if ($skipHeader) {
                // Skipping the header column (first row)
                $skipHeader = false;
                continue;
            }
            $tradeType = $content[3] ?? '';
            $feeUnit   = $content[11] ?? ''; //Ignore stablecoin transactions
            $symbol    = $content[4] ?? '';
            $type    = $content[5] ?? '';
            $amount    = $content[6] ?? '';
            $price = $content[8] ?? '';
            $date = $content[2] ?? '';

            // Filter out unwanted rows
            if (in_array($feeUnit, $excludedFeeUnits)) {
                continue;
            }
            if (stripos($symbol, 'CONVERT') !== false) {
                continue;
            }

            $asset = strtolower(explode('-', $symbol)[0]);
            if($tradeType === 'Spot' && isset($listId[$asset])) {
                $setId[] = $listId[$asset];
                $transactions[] = [
                    'portfolio_id' => $portfolio->id,
                    'asset_id' => $listId[$asset],
                    'exchange_id' => $this->exchangeId,
                    'quantity' => $amount,
                    'price' => $price,
                    'type' => strtoupper($type),
                    'transact_date' => $date,
                ];
            }
        }
        return [$transactions, $setId];
    }

    private function formatBybitCSVData($csvContents, $portfolio, $excludedFeeUnits, $listId)
    {
        $skipHeader = true;
        $transactions = [];
        $setId = [];
        
        foreach ($csvContents as $content) {
            if ($skipHeader) {
                // Skipping the header column (first row)
                $skipHeader = false;
                continue;
            }
            $tradeType = $content[2] ?? '';
            $currency = $content[0] ?? '';
            $type    = $content[3] ?? '';
            $amount    = $content[4] ?? '';
            $price = $content[6] ?? '';
            $date = $content[13] ?? '';

            // Filter out unwanted rows
            if (in_array($currency, $excludedFeeUnits) || $tradeType !== 'TRADE') {
                continue;
            }

            $asset = strtolower($currency);
            if(isset($listId[$asset])) {
                $setId[] = $listId[$asset];
                $transactions[] = [
                    'portfolio_id' => $portfolio->id,
                    'asset_id' => $listId[$asset],
                    'exchange_id' => $this->exchangeId,
                    'quantity' => $amount,
                    'price' => $price,
                    'type' => strtoupper($type),
                    'transact_date' => $date,
                ];
            }
        }
        return [$transactions, $setId];
    }
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $fileStream = fopen(storage_path('app/public/' . $this->file), 'r');    
        $csvContents = [];
        // Reading the file line by line into an array
        while (($line = fgetcsv($fileStream)) !== false) {
            $csvContents[] = $line;
        }
        fclose($fileStream);

        $portfolio = Portfolio::select('id')->where('user_id', $this->userId)->first();
        $excludedFeeUnits = ['USDT', 'USDC'];
        $listId = $portfolio->assets->pluck('id','symbol')->toArray();

        switch ($this->exchangeId) {
            // case 1: // Binance
            //     [$transactions, $setId] = $this->formatOkxCSVData($csvContents, $portfolio, $excludedFeeUnits, $listId);
            //     break;
            case 2: // OKX
                [$transactions, $setId] = $this->formatOkxCSVData($csvContents, $portfolio, $excludedFeeUnits, $listId);
                break;
            case 3: // Bybit
                [$transactions, $setId] = $this->formatBybitCSVData($csvContents, $portfolio, $excludedFeeUnits, $listId);
                break;
            default:
                Log::error("Unsupported exchange ID: {$this->exchangeId}");
                return;
        }

        $setId = array_unique($setId);
        DB::beginTransaction();
        try {
            Transaction::where('portfolio_id', $portfolio->id)->where('exchange_id', $this->exchangeId)->delete();
            Transaction::insert($transactions);
            DB::commit();

            foreach ($setId as $asset) {
                // Update the portfolio asset with the latest transaction
                $transaction_history = Transaction::where('portfolio_id', $portfolio->id)
                    ->where('asset_id', $asset)
                    ->orderBy('transact_date')
                    ->get();
                // Re-calculate avg_price
                $avg_price = PortfolioService::calculateAvgPrice($transaction_history);
                $portfolio->assets()->updateExistingPivot($asset, [
                    'avg_price' => $avg_price['average_price'] ?? 0
                ]);
            }
            $count = count($setId);
            $emit = Http::post(config('app.cex_service_url') . '/emit-event', [
                'event' => 'import-csv-transactions',
                'data' => ['success' => true, 'message' => "{$count} tokens updated transactions successfully"],
                'userId' => $this->userId,
            ])->throw()->json();
        } catch (\Exception $e) {
            DB::rollBack();
            $emit = Http::post(config('app.cex_service_url') . '/emit-event', [
                'event' => 'import-csv-transactions',
                'data' => ['success' => false, 'error' => $e->getMessage()],
                'userId' => $this->userId,
            ])->throw()->json();
            Log::error("Failed to import transactions: " . $e->getMessage());
        }
    
        // Storage::delete(storage_path('app/public/' . $this->file));
    }
}
