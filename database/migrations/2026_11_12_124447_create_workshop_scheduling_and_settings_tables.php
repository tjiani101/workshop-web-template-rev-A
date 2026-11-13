<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('daily_capacity_minutes')->default(600);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('mechanic_specializations', function (Blueprint $table): void {
            $table->foreignId('mechanic_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['mechanic_profile_id', 'service_category_id']);
        });

        Schema::create('mechanic_leave_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mechanic_profile_id')->constrained()->cascadeOnDelete();
            $table->date('leave_date');
            $table->string('reason', 180)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['mechanic_profile_id', 'leave_date']);
        });

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 120)->unique();
            $table->json('setting_value');
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->string('status', 24)->default('reserved');
            $table->timestamp('reserved_until')->nullable();
            $table->boolean('overnight')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scheduled_start_at', 'scheduled_end_at', 'status']);
            $table->index(['booking_id', 'status']);
        });

        Schema::create('mechanic_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('assigned_minutes');
            $table->boolean('specialization_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['appointment_id', 'mechanic_profile_id']);
            $table->index(['mechanic_profile_id', 'created_at']);
        });

        Schema::create('appointment_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->dateTime('previous_start_at')->nullable();
            $table->dateTime('previous_end_at')->nullable();
            $table->dateTime('new_start_at')->nullable();
            $table->dateTime('new_end_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_histories');
        Schema::dropIfExists('mechanic_assignments');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('mechanic_leave_days');
        Schema::dropIfExists('mechanic_specializations');
        Schema::dropIfExists('mechanic_profiles');
    }
};
