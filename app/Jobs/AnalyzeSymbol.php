<?php

namespace App\Jobs;

use App\DataProviders\CexServiceProvider;
use App\Services\TradingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class AnalyzeSymbol implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $symbol;
    protected string $jobId;
    protected $userId;
    protected $tradingService;

    public function __construct(TradingService $tradingService, string $symbol, string $jobId, $userId)
    {
        $this->tradingService = $tradingService;
        $this->symbol = $symbol;
        $this->jobId = $jobId;
        $this->userId = $userId;
    }

    public function handle(CexServiceProvider $cexService): void
    {
        try {
            $analysisResult = $this->tradingService->analyzeSymbol($this->symbol);
            
            if (!empty($analysisResult)) {
                Redis::set("analysis:{$this->symbol}", json_encode($analysisResult), 'EX', 900);
            }
            
            $cexService->emitEvent(
                'analyze-symbol', 
                ['success' => true, 'analysis' =>  $analysisResult],
                $this->userId
            );
        } catch (\Exception $e) {
            $cexService->emitEvent(
                'analyze-symbol', 
                ['success' => false, 'error' => $e->getMessage()],
                $this->userId
            );
        }
    }
}