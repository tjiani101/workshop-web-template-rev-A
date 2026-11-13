<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->string('verification_status', 32)->default('needs_verification');
            $table->string('data_source', 255)->nullable();
            $table->text('notes')->nullable();
        });

        Schema::create('vehicle_generations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->cascadeOnDelete();
            $table->string('name', 140);
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('verification_status', 32)->default('needs_verification');
            $table->string('data_source', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['vehicle_model_id', 'name']);
        });

        Schema::create('vehicle_model_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->cascadeOnDelete();
            $table->foreignId('vehicle_generation_id')->constrained('vehicle_generations')->cascadeOnDelete();
            $table->unsignedSmallInteger('model_year');
            $table->boolean('is_active')->default(true);
            $table->string('verification_status', 32)->default('needs_verification');
            $table->string('data_source', 255)->nullable();
            $table->timestamps();
            $table->unique(['vehicle_generation_id', 'model_year']);
            $table->index(['vehicle_model_id', 'model_year']);
        });

        Schema::create('vehicle_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->cascadeOnDelete();
            $table->foreignId('vehicle_generation_id')->constrained('vehicle_generations')->cascadeOnDelete();
            $table->string('name', 140);
            $table->boolean('is_active')->default(true);
            $table->string('verification_status', 32)->default('needs_verification');
            $table->string('data_source', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['vehicle_generation_id', 'name']);
        });

        Schema::create('vehicle_variant_year', function (Blueprint $table): void {
            $table->foreignId('vehicle_variant_id')->constrained('vehicle_variants')->cascadeOnDelete();
            $table->foreignId('vehicle_model_year_id')->constrained('vehicle_model_years')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['vehicle_variant_id', 'vehicle_model_year_id'], 'vehicle_variant_year_pk');
        });

        Schema::create('vehicle_variant_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_variant_id')->constrained('vehicle_variants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('value', 160);
            $table->string('unit', 40)->nullable();
            $table->timestamps();
            $table->index(['vehicle_variant_id', 'name']);
        });

        Schema::table('customer_vehicles', function (Blueprint $table): void {
            $table->foreignId('vehicle_variant_id')->nullable()->after('model_id')->constrained('vehicle_variants')->restrictOnDelete();
            $table->boolean('is_default')->default(false)->after('nickname');
        });

        Schema::create('customer_vehicle_model_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manufacturer', 120);
            $table->string('model_name', 160);
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('variant_details', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->string('labor_category', 48)->nullable();
            $table->string('labor_pricing_mode', 16)->default('fixed');
            $table->string('labor_group', 80)->nullable();
        });

        Schema::create('service_part_variants', function (Blueprint $table): void {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_variant_id')->constrained()->cascadeOnDelete();
            $table->string('component_label', 140)->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('is_required')->default(false);
            $table->timestamps();
            $table->primary(['service_id', 'part_variant_id'], 'service_part_variants_pk');
        });

        Schema::table('part_variant_vehicle_model', function (Blueprint $table): void {
            $table->foreignId('vehicle_variant_id')->nullable()->after('vehicle_model_id')->constrained('vehicle_variants')->nullOnDelete();
            $table->unsignedSmallInteger('compatible_year_from')->nullable();
            $table->unsignedSmallInteger('compatible_year_to')->nullable();
            $table->string('source_name', 160)->nullable();
            $table->string('source_type', 24)->default('unverified');
            $table->string('part_number', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_part_variants');
        Schema::table('part_variant_vehicle_model', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_variant_id');
            $table->dropColumn(['compatible_year_from', 'compatible_year_to', 'source_name', 'source_type', 'part_number']);
        });
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['labor_category', 'labor_pricing_mode', 'labor_group']);
        });
        Schema::dropIfExists('customer_vehicle_model_requests');
        Schema::table('customer_vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_variant_id');
            $table->dropColumn('is_default');
        });
        Schema::dropIfExists('vehicle_variant_attributes');
        Schema::dropIfExists('vehicle_variant_year');
        Schema::dropIfExists('vehicle_variants');
        Schema::dropIfExists('vehicle_model_years');
        Schema::dropIfExists('vehicle_generations');
        Schema::table('vehicle_models', function (Blueprint $table): void {
            $table->dropColumn(['verification_status', 'data_source', 'notes']);
        });
    }
};
