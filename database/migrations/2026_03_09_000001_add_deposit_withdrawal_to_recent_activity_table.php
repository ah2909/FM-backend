<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        DB::statement("ALTER TABLE recent_activity MODIFY COLUMN type ENUM(
            'Add asset',
            'Remove asset',
            'Sync asset transactions',
            'Update asset',
            'Deposit',
            'Withdrawn'
        ) NOT NULL");

        Schema::table('recent_activity', function (Blueprint $table) {
            $table->float('amount')->nullable()->after('is_read');
        });
    }

    public function down(): void {
        Schema::table('recent_activity', function (Blueprint $table) {
            $table->dropColumn('amount');
        });

        DB::statement("ALTER TABLE recent_activity MODIFY COLUMN type ENUM(
            'Add asset',
            'Remove asset',
            'Sync asset transactions',
            'Update asset'
        ) NOT NULL");
    }
};
