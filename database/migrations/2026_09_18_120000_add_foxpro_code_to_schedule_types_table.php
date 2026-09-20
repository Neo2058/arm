<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_types', function (Blueprint $table) {
            $table->string('foxpro_code', 8)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_types', function (Blueprint $table) {
            $table->dropUnique(['foxpro_code']);
            $table->dropColumn('foxpro_code');
        });
    }
};
