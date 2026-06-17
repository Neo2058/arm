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
        Schema::table('routes_catalog', function (Blueprint $table) {
            if (!Schema::hasColumn('routes_catalog', 'schedule_type_id')) {
                $table->foreignId('schedule_type_id')
                      ->nullable()
                      ->after('id')
                      ->constrained('schedule_types')
                      ->nullOnDelete();
            }
            if (!Schema::hasColumn('routes_catalog', 'shift_type')) {
                $table->string('shift_type')
                      ->nullable()
                      ->after('schedule_type_id')
                      ->comment('1-с ночи, 2-ранняя, 3-вечёрка, 3+-ранняя ночь, 4+-ночь, 5+-поздняя ночь');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routes_catalog', function (Blueprint $table) {
            if (Schema::hasColumn('routes_catalog', 'shift_type')) {
                $table->dropColumn('shift_type');
            }
            if (Schema::hasColumn('routes_catalog', 'schedule_type_id')) {
                $table->dropForeign(['schedule_type_id']);
                $table->dropColumn('schedule_type_id');
            }
        });
    }
};
