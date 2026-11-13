<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_recommendations', function (Blueprint $table): void {
            $table->string('fitment_status', 20)->default('unverified')->after('urgency');
            $table->text('fitment_note')->nullable()->after('fitment_status');
            $table->foreignId('fitment_confirmed_by')->nullable()->after('fitment_note')->constrained('users')->nullOnDelete();
            $table->timestamp('fitment_confirmed_at')->nullable()->after('fitment_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_recommendations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fitment_confirmed_by');
            $table->dropColumn(['fitment_confirmed_at', 'fitment_note', 'fitment_status']);
        });
    }
};
