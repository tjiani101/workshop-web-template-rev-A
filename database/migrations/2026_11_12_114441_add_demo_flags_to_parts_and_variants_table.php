<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('is_public');
        });

        Schema::table('part_variants', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('part_variants', function (Blueprint $table): void {
            $table->dropColumn('is_demo');
        });

        Schema::table('parts', function (Blueprint $table): void {
            $table->dropColumn('is_demo');
        });
    }
};
