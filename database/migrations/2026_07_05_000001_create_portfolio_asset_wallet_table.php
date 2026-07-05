<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('portfolio_asset_wallet', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('portfolio_asset_id');
            $table->string('exchange', 20);
            $table->string('wallet_type', 20);
            // Signed: Bybit UTA per-coin equity can go negative when borrowing
            $table->double('amount')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['portfolio_asset_id', 'exchange', 'wallet_type'], 'paw_unique');
            $table->foreign('portfolio_asset_id')->references('id')->on('portfolio_asset')->onDelete('cascade');
        });

        // 4-byte FLOAT truncates past ~7 significant digits; summing balances across wallets compounds it
        Schema::table('portfolio_asset', function (Blueprint $table) {
            $table->double('amount')->unsigned()->default(0)->change();
            $table->double('avg_price')->unsigned()->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_asset_wallet');
        Schema::table('portfolio_asset', function (Blueprint $table) {
            $table->float('amount')->unsigned()->default(0)->change();
            $table->float('avg_price')->unsigned()->nullable()->change();
        });
    }
};
