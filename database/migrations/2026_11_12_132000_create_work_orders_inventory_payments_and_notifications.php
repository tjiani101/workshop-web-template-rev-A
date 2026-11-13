<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('scheduled');
            $table->unsignedInteger('odometer_km')->nullable();
            $table->text('check_in_notes')->nullable();
            $table->text('completion_notes')->nullable();
            $table->unsignedSmallInteger('actual_work_minutes')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('work_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('recommendation_id')->nullable();
            $table->string('label_snapshot', 180);
            $table->decimal('quantity', 8, 2)->default(1);
            $table->unsignedBigInteger('unit_price_rupiah')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('actual_minutes')->nullable();
            $table->text('mechanic_notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('work_order_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('label', 180);
            $table->boolean('is_complete')->default(false);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->text('summary')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inspection_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('severity', 20)->default('observation');
            $table->string('title', 180);
            $table->text('description');
            $table->text('technical_finding')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['inspection_id', 'category']);
        });

        Schema::create('inspection_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_finding_id')->constrained()->cascadeOnDelete();
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path', 255);
            $table->string('original_name', 180);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('work_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_finding_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('part_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mechanic_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('reason');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->unsignedBigInteger('labor_price_rupiah')->default(0);
            $table->unsignedBigInteger('part_price_rupiah')->default(0);
            $table->string('urgency', 20)->default('recommended');
            $table->string('customer_decision', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'customer_decision']);
        });
        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->foreign('recommendation_id')->references('id')->on('work_recommendations')->nullOnDelete();
        });

        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 40)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_variant_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('physical_quantity', 12, 2)->default(0);
            $table->decimal('reserved_quantity', 12, 2)->default(0);
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->unsignedBigInteger('purchase_cost_rupiah')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('part_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('work_recommendation_id')->nullable()->constrained('work_recommendations')->cascadeOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->string('status', 20)->default('reserved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['part_variant_id', 'status']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_level_id')->constrained()->restrictOnDelete();
            $table->string('movement_type', 24);
            $table->decimal('quantity_delta', 12, 2);
            $table->decimal('physical_after', 12, 2);
            $table->decimal('reserved_after', 12, 2);
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('stock_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_level_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->unsignedBigInteger('unit_cost_rupiah');
            $table->string('supplier_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->useCurrent();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('payment_reference', 60)->unique();
            $table->string('payment_kind', 24);
            $table->string('method', 24);
            $table->unsignedBigInteger('amount_rupiah');
            $table->string('status', 24)->default('submitted');
            $table->string('customer_reference', 120)->nullable();
            $table->string('proof_disk', 40)->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->text('customer_note')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'status']);
        });

        Schema::create('payment_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 24);
            $table->text('note')->nullable();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_rupiah');
            $table->string('method', 24);
            $table->string('status', 20)->default('pending');
            $table->string('reference', 120)->nullable();
            $table->text('reason');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title', 180);
            $table->text('body');
            $table->string('url', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_verifications');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('stock_receipts');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_recommendations');
        Schema::dropIfExists('inspection_media');
        Schema::dropIfExists('inspection_findings');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('work_order_checklists');
        Schema::dropIfExists('work_orders');
    }
};
