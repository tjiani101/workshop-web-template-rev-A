<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('vehicle_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('vehicle_brands')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('vehicle_categories')->restrictOnDelete();
            $table->string('name', 140);
            $table->string('slug', 160);
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->unique(['brand_id', 'slug']);
            $table->index(['category_id', 'is_active']);
        });

        Schema::create('customer_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('model_id')->constrained('vehicle_models')->restrictOnDelete();
            $table->string('registration_number', 32)->nullable()->unique();
            $table->string('vin', 64)->nullable()->unique();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->string('nickname', 80)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('part_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('part_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('part_categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('part_brands')->nullOnDelete();
            $table->string('name', 180);
            $table->string('slug', 200)->unique();
            $table->string('sku', 80)->nullable()->unique();
            $table->string('part_type', 24)->default('aftermarket');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('purchase_cost_rupiah')->nullable();
            $table->unsignedBigInteger('selling_price_rupiah')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->index(['category_id', 'is_active', 'is_public']);
        });

        Schema::create('part_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('sku', 80)->nullable()->unique();
            $table->string('variant_code', 80)->nullable();
            $table->unsignedBigInteger('purchase_cost_rupiah')->nullable();
            $table->unsignedBigInteger('selling_price_rupiah')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['part_id', 'is_active']);
        });

        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('unit', 24)->nullable();
            $table->timestamps();
        });

        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->string('value', 100);
            $table->string('code', 80)->nullable();
            $table->timestamps();
            $table->unique(['attribute_id', 'value']);
        });

        Schema::create('part_variant_attribute', function (Blueprint $table): void {
            $table->foreignId('part_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('product_attribute_values')->cascadeOnDelete();
            $table->primary(['part_variant_id', 'attribute_value_id']);
        });

        Schema::create('part_variant_vehicle_model', function (Blueprint $table): void {
            $table->foreignId('part_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->string('compatibility_status', 20)->default('unverified');
            $table->string('source_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->primary(['part_variant_id', 'vehicle_model_id']);
        });

        Schema::create('service_vehicle_model', function (Blueprint $table): void {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->string('compatibility_status', 20)->default('unverified');
            $table->string('source_reference')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->primary(['service_id', 'vehicle_model_id']);
        });

        Schema::create('package_vehicle_model', function (Blueprint $table): void {
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_model_id')->constrained()->restrictOnDelete();
            $table->string('compatibility_status', 20)->default('unverified');
            $table->string('source_reference')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->primary(['package_id', 'vehicle_model_id']);
        });

        Schema::create('service_option_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('selection_type', 20)->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('service_option_groups')->cascadeOnDelete();
            $table->string('name', 140);
            $table->text('description')->nullable();
            $table->bigInteger('price_delta_rupiah')->default(0);
            $table->integer('duration_delta_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('package_items', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->after('package_id')->constrained()->nullOnDelete();
            $table->foreignId('part_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
            $table->foreignId('part_variant_id')->nullable()->after('part_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('package_items', function (Blueprint $table): void {
            $table->dropForeign(['part_variant_id']);
            $table->dropForeign(['part_id']);
            $table->dropForeign(['service_id']);
            $table->dropColumn(['part_variant_id', 'part_id', 'service_id']);
        });

        Schema::dropIfExists('service_options');
        Schema::dropIfExists('service_option_groups');
        Schema::dropIfExists('package_vehicle_model');
        Schema::dropIfExists('service_vehicle_model');
        Schema::dropIfExists('part_variant_vehicle_model');
        Schema::dropIfExists('part_variant_attribute');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('part_variants');
        Schema::dropIfExists('parts');
        Schema::dropIfExists('part_brands');
        Schema::dropIfExists('part_categories');
        Schema::dropIfExists('customer_vehicles');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_brands');
        Schema::dropIfExists('vehicle_categories');
    }
};
