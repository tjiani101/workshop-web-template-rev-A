<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_vehicle_prices', function (Blueprint $table): void {
            $table->unsignedBigInteger('parts_cost_rupiah')->nullable()->after('year_to');
        });
    }

    public function down(): void
    {
        Schema::table('package_vehicle_prices', function (Blueprint $table): void {
            $table->dropColumn('parts_cost_rupiah');
        });
    }
};
