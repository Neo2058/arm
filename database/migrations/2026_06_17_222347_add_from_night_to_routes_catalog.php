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
            if (!Schema::hasColumn('routes_catalog', 'from_night')) {
                $table->string('from_night', 50)->nullable()->after('route_number')
                    ->comment('Номер маршрута продолжения с ночи (утренний вариант)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routes_catalog', function (Blueprint $table) {
            if (Schema::hasColumn('routes_catalog', 'from_night')) {
                $table->dropColumn('from_night');
            }
        });
    }
};
