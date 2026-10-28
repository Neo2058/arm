<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->string('absence_kind', 16)->nullable();
            $table->date('absence_end')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('work_shifts')->whereNotNull('absence_kind')->exists()) {
            throw new RuntimeException('Personal absence records must be preserved; roll back code without dropping their data.');
        }
        Schema::table('work_shifts', fn (Blueprint $table) => $table->dropColumn(['absence_kind', 'absence_end']));
    }
};
