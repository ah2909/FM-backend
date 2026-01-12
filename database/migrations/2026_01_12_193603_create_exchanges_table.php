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
        Schema::create('exchanges', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedTinyInteger('cex_id');
            $table->string('api_key', 500);
            $table->string('secret_key', 500);
            $table->string('password', 255)->nullable();
            $table->unsignedInteger('user_id');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->foreign('cex_id')->references('id')->on('CEXs')->onDelete('no action')->onUpdate('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
