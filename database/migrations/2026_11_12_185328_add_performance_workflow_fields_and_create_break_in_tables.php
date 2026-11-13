<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('requires_full_day')->default(false);
            $table->boolean('requires_operation_consent')->default(false);
            $table->boolean('requires_bore_up_package')->default(false);
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->boolean('requires_fitment_confirmation')->default(false);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('operation_consent_at')->nullable();
        });

        Schema::create('engine_break_in_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('booked');
            $table->unsignedBigInteger('estimated_fuel_budget_rupiah')->nullable();
            $table->unsignedBigInteger('approved_fuel_budget_rupiah')->nullable();
            $table->unsignedBigInteger('pending_fuel_budget_rupiah')->nullable();
            $table->text('pending_budget_note')->nullable();
            $table->timestamp('budget_approved_at')->nullable();
            $table->timestamp('customer_consent_at');
            $table->string('customer_consent_version', 40);
            $table->string('sop_reference', 180)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('start_odometer_km')->nullable();
            $table->unsignedInteger('end_odometer_km')->nullable();
            $table->text('procedure_notes')->nullable();
            $table->text('final_inspection_notes')->nullable();
            $table->timestamp('final_inspection_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'started_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('engine_break_in_program_id')->nullable()->after('booking_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('engine_break_in_fuel_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('engine_break_in_program_id')->constrained()->cascadeOnDelete();
            $table->dateTime('purchased_at');
            $table->decimal('quantity_liters', 8, 3)->nullable();
            $table->unsignedBigInteger('expense_rupiah');
            $table->unsignedBigInteger('customer_contribution_rupiah');
            $table->unsignedBigInteger('minex_subsidy_rupiah');
            $table->string('receipt_path', 255);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['engine_break_in_program_id', 'purchased_at'], 'break_in_fuel_program_purchased_idx');
        });

        Schema::create('engine_build_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('original_bore_mm', 7, 2)->nullable();
            $table->decimal('selected_bore_mm', 7, 2)->nullable();
            $table->decimal('stroke_mm', 7, 2)->nullable();
            $table->unsignedTinyInteger('cylinder_count')->nullable();
            $table->decimal('calculated_displacement_cc', 9, 2)->nullable();
            $table->foreignId('selected_piston_variant_id')->nullable()->constrained('part_variants')->nullOnDelete();
            $table->foreignId('selected_block_variant_id')->nullable()->constrained('part_variants')->nullOnDelete();
            $table->foreignId('injector_variant_id')->nullable()->constrained('part_variants')->nullOnDelete();
            $table->text('injector_information')->nullable();
            $table->json('supporting_components')->nullable();
            $table->string('compatibility_status', 40)->default('unverified');
            $table->text('compatibility_verification_note')->nullable();
            $table->foreignId('compatibility_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('compatibility_verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['compatibility_status', 'updated_at'], 'engine_build_compat_status_updated_idx');
        });

        $storedRates = DB::table('business_settings')->where('setting_key', 'labor_rates')->value('setting_value');
        if ($storedRates !== null) {
            $rates = is_array($storedRates) ? $storedRates : json_decode($storedRates, true);
            if (is_array($rates)) {
                foreach (['electrical_diagnosis', 'electrical_installation'] as $category) {
                    if (isset($rates[$category]) && ! array_key_exists('provisional', $rates[$category])) {
                        $rates[$category]['active'] = false;
                        $rates[$category]['provisional'] = true;
                    }
                }
                DB::table('business_settings')->where('setting_key', 'labor_rates')->update(['setting_value' => json_encode($rates, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('engine_break_in_program_id');
        });

        Schema::dropIfExists('engine_build_configurations');
        Schema::dropIfExists('engine_break_in_fuel_expenses');
        Schema::dropIfExists('engine_break_in_programs');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('operation_consent_at');
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn('requires_fitment_confirmation');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['requires_full_day', 'requires_operation_consent', 'requires_bore_up_package']);
        });
    }
};
