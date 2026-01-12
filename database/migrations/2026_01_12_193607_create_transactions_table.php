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
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedTinyInteger('exchange_id');
            $table->unsignedInteger('portfolio_id');
            $table->unsignedInteger('asset_id');
            $table->float('quantity');
            $table->float('price');
            $table->enum('type', ['BUY', 'SELL', 'DEPOSIT', 'WITHDRAWAL'])->default('BUY');
            $table->dateTime('transact_date');
            $table->timestamp('updated_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['portfolio_id', 'asset_id', 'exchange_id', 'transact_date', 'type'], 'transactions_unique_constraint');

            $table->foreign('exchange_id')->references('id')->on('CEXs')->onDelete('no action')->onUpdate('no action');
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('no action')->onUpdate('no action');
            $table->foreign('portfolio_id')->references('id')->on('portfolios')->onDelete('no action')->onUpdate('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
