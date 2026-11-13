<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->foreignId('part_variant_id')->nullable()->after('recommendation_id')->constrained()->nullOnDelete();
            $table->foreignId('stock_reservation_id')->nullable()->after('part_variant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_reservation_id');
            $table->dropConstrainedForeignId('part_variant_id');
        });
    }
};
