<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('show_on_home')->default(false);
            $table->boolean('show_on_services')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['is_active', 'show_on_home', 'sort_order']);
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('summary', 280);
            $table->text('description');
            $table->json('included_items')->nullable();
            $table->json('excluded_items')->nullable();
            $table->unsignedBigInteger('labor_price_rupiah')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['is_active', 'is_public', 'is_featured', 'sort_order']);
        });

        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('summary', 280);
            $table->text('description');
            $table->unsignedBigInteger('base_price_rupiah')->nullable();
            $table->unsignedBigInteger('labor_price_rupiah')->nullable();
            $table->unsignedBigInteger('discount_rupiah')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['is_active', 'is_public', 'is_featured', 'sort_order']);
        });

        Schema::create('package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('component_type', 40)->default('service');
            $table->string('label', 180);
            $table->text('description')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->unsignedBigInteger('extra_price_rupiah')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['package_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
