<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('booking_number', 40)->unique();
            $table->string('reference', 48)->unique();
            $table->uuid('submission_token')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_vehicle_id')->nullable()->constrained('customer_vehicles')->nullOnDelete();
            $table->string('customer_type', 16);
            $table->string('customer_name', 120);
            $table->string('customer_email', 254);
            $table->string('customer_phone', 40);
            $table->json('vehicle_snapshot');
            $table->string('status', 32)->default('pending_approval');
            $table->string('payment_status', 32)->default('not_due');
            $table->unsignedTinyInteger('payment_percentage')->nullable();
            $table->string('payment_method', 24)->nullable();
            $table->unsignedBigInteger('subtotal_rupiah')->default(0);
            $table->unsignedBigInteger('discount_rupiah')->default(0);
            $table->unsignedBigInteger('approved_total_rupiah')->nullable();
            $table->unsignedBigInteger('additional_work_rupiah')->default(0);
            $table->unsignedBigInteger('amount_paid_rupiah')->default(0);
            $table->unsignedBigInteger('refund_amount_rupiah')->default(0);
            $table->boolean('has_unpriced_items')->default(false);
            $table->boolean('requires_fitment_confirmation')->default(false);
            $table->unsignedSmallInteger('estimated_duration_minutes')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('payment_deadline_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['payment_status', 'payment_deadline_at']);
        });

        Schema::create('booking_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('part_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type', 32);
            $table->string('label_snapshot', 180);
            $table->text('description_snapshot')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->unsignedBigInteger('labor_price_rupiah')->nullable();
            $table->unsignedBigInteger('unit_price_rupiah')->nullable();
            $table->unsignedBigInteger('discount_rupiah')->default(0);
            $table->unsignedBigInteger('line_total_rupiah')->default(0);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->json('catalog_snapshot');
            $table->timestamps();
            $table->index(['booking_id', 'item_type']);
        });

        Schema::create('booking_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
    }
};
