<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('naryad_norms', 'monthly_hours')) {
            Schema::table('naryad_norms', function (Blueprint $table) {
                $table->json('monthly_hours')->nullable(); // e.g. {"2026-06": 160, "2026-07": 150}
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('naryad_norms', 'monthly_hours')) {
            Schema::table('naryad_norms', function (Blueprint $table) {
                $table->dropColumn('monthly_hours');
            });
        }
    }
};
