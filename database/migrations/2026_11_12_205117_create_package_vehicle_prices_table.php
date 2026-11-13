<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_vehicle_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->unsignedBigInteger('parts_selling_rupiah')->default(0);
            $table->unsignedBigInteger('fixed_labor_rupiah')->default(0);
            $table->unsignedBigInteger('consumables_rupiah')->default(0);
            $table->unsignedBigInteger('additional_costs_rupiah')->default(0);
            $table->unsignedBigInteger('discount_rupiah')->default(0);
            $table->string('price_source', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['package_id', 'vehicle_model_id', 'status'], 'package_vehicle_price_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_vehicle_prices');
    }
};
