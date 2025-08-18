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
        Schema::table('transactions', function (Blueprint $table) {
            $table->datetime('transact_date')->change();
            $table->unique([
                'portfolio_id', 
                'asset_id', 
                'exchange_id', 
                'transact_date',
                'type'
            ], 'transactions_unique_constraint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_unique_constraint');
            $table->date('transact_date')->change();
            $table->unique([
                'portfolio_id', 
                'asset_id', 
                'exchange_id', 
                'transact_date',
                'type'
            ], 'transactions_unique_constraint');
        });
    }
};
