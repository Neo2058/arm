<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arm_shift_breakdowns', function (Blueprint $table) {
            $table->string('extra_mark', 4)->nullable()->after('notes');
        });

        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->string('mark_code', 4)->nullable()->after('registered');
        });
    }

    public function down(): void
    {
        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->dropColumn('mark_code');
        });
        Schema::table('arm_shift_breakdowns', function (Blueprint $table) {
            $table->dropColumn('extra_mark');
        });
    }
};
