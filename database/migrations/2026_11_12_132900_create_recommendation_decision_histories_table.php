<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_decision_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_recommendation_id')->constrained()->cascadeOnDelete();
            $table->string('from_decision', 20)->nullable();
            $table->string('to_decision', 20);
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['work_recommendation_id', 'created_at'], 'recommendation_decisions_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_decision_histories');
    }
};
