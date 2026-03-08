<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('recent_activity', function (Blueprint $table) {
            $table->boolean('is_read')->default(false)->after('transaction_count');
        });
    }
    public function down(): void {
        Schema::table('recent_activity', function (Blueprint $table) {
            $table->dropColumn('is_read');
        });
    }
};
