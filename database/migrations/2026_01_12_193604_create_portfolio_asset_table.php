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
        Schema::create('portfolio_asset', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('portfolio_id');
            $table->unsignedInteger('asset_id');
            $table->float('amount')->unsigned()->default(0);
            $table->float('avg_price')->unsigned()->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->foreign('portfolio_id')->references('id')->on('portfolios')->onDelete('no action')->onUpdate('no action');
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('no action')->onUpdate('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_asset');
    }
};
