<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->foreignId('arm_shift_breakdown_id')->nullable()->after('assigned_by')
                ->constrained('arm_shift_breakdowns')->nullOnDelete();
            $table->string('work_code', 8)->nullable();
            $table->string('shift_code', 8)->nullable();
            $table->decimal('hours_total', 6, 3)->nullable();
            $table->decimal('hours_line', 6, 3)->nullable();
            $table->decimal('hours_reserve', 6, 3)->nullable();
            $table->decimal('hours_night', 6, 3)->nullable();
            $table->decimal('hours_night_reserve', 6, 3)->nullable();
            $table->decimal('hours_evening', 6, 3)->nullable();
            $table->decimal('hours_evening_reserve', 6, 3)->nullable();
            $table->decimal('hours_break', 6, 3)->nullable();
            $table->decimal('hours_break_reserve', 6, 3)->nullable();
            $table->decimal('hours_holiday', 6, 3)->nullable();
            $table->decimal('hours_holiday_reserve', 6, 3)->nullable();
            $table->decimal('hours_overtime', 6, 3)->nullable();
            $table->boolean('two_person')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arm_shift_breakdown_id');
            $table->dropColumn([
                'work_code',
                'shift_code',
                'hours_total',
                'hours_line',
                'hours_reserve',
                'hours_night',
                'hours_night_reserve',
                'hours_evening',
                'hours_evening_reserve',
                'hours_break',
                'hours_break_reserve',
                'hours_holiday',
                'hours_holiday_reserve',
                'hours_overtime',
                'two_person',
            ]);
        });
    }
};
