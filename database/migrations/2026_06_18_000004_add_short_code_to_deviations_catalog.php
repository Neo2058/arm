<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deviations_catalog', function (Blueprint $table) {
            if (!Schema::hasColumn('deviations_catalog', 'short_code')) {
                $table->string('short_code', 10)->nullable()->after('sys_key')
                      ->comment('Короткий код для статистики в сетке (Б, В и т.д.)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deviations_catalog', function (Blueprint $table) {
            $table->dropColumn('short_code');
        });
    }
};
