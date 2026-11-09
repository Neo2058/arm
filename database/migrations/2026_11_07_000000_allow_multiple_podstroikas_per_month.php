<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('podstroikas')) {
            Schema::table('podstroikas', function (Blueprint $table) {
                // Drop old single-per-month unique index
                try {
                    $table->dropUnique(['user_id', 'for_month']);
                } catch (\Throwable $e) {
                    try {
                        $table->dropUnique('podstroikas_user_id_for_month_unique');
                    } catch (\Throwable $e2) {}
                }

                // Add unique index per driver per day so one can't book conflicting requests on the same day,
                // but can submit multiple requests across the month up to limit.
                if (Schema::hasColumn('podstroikas', 'target_date')) {
                    $table->unique(['user_id', 'target_date'], 'podstroikas_user_id_target_date_unique');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('podstroikas')) {
            Schema::table('podstroikas', function (Blueprint $table) {
                try {
                    $table->dropUnique('podstroikas_user_id_target_date_unique');
                } catch (\Throwable $e) {}

                $table->unique(['user_id', 'for_month'], 'podstroikas_user_id_for_month_unique');
            });
        }
    }
};
