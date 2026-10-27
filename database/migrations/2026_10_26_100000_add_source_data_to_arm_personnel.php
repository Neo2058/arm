<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arm_personnel', function (Blueprint $table) {
            $table->json('source_data')->nullable()->comment('Все исходные поля последнего импорта LKM');
        });
    }

    public function down(): void
    {
        Schema::table('arm_personnel', fn (Blueprint $table) => $table->dropColumn('source_data'));
    }
};
