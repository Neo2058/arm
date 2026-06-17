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
            if (!Schema::hasColumn('routes_catalog', 'night_parity')) {
                $table->string('night_parity', 10)->nullable()->after('shift_type')
                    ->comment('чётный / нечётный для ночных смен (even/odd)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routes_catalog', function (Blueprint $table) {
            if (Schema::hasColumn('routes_catalog', 'night_parity')) {
                $table->dropColumn('night_parity');
            }
        });
    }
};
