<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arm_shift_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_type_id')->nullable()->constrained('schedule_types')->nullOnDelete();
            $table->string('graph_code', 8)->default('');
            $table->string('route_code', 8)->default('');
            $table->string('shift_code', 8)->default('');
            $table->string('sequence', 8)->default('');
            $table->string('position_code', 4)->default('');
            $table->decimal('start_hours', 5, 2)->default(0);
            $table->decimal('end_hours', 5, 2)->default(0);
            $table->decimal('hours_total', 6, 3)->default(0);
            $table->decimal('hours_line', 6, 3)->default(0);
            $table->decimal('hours_line_2', 6, 3)->default(0);
            $table->decimal('hours_reserve', 6, 3)->default(0);
            $table->decimal('hours_reserve_2', 6, 3)->default(0);
            $table->decimal('hours_night', 6, 3)->default(0);
            $table->decimal('hours_night_reserve', 6, 3)->default(0);
            $table->decimal('hours_night_2', 6, 3)->default(0);
            $table->decimal('hours_night_2_reserve', 6, 3)->default(0);
            $table->decimal('hours_evening', 6, 3)->default(0);
            $table->decimal('hours_evening_reserve', 6, 3)->default(0);
            $table->decimal('hours_evening_2', 6, 3)->default(0);
            $table->decimal('hours_evening_2_reserve', 6, 3)->default(0);
            $table->decimal('hours_break', 6, 3)->default(0);
            $table->decimal('hours_break_reserve', 6, 3)->default(0);
            $table->decimal('hours_break_2', 6, 3)->default(0);
            $table->decimal('hours_break_2_reserve', 6, 3)->default(0);
            $table->string('appearance_start', 16)->nullable();
            $table->string('appearance_end', 16)->nullable();
            $table->string('content', 40)->nullable();
            $table->string('morning_code', 8)->nullable();
            $table->string('morning_shift', 8)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['graph_code', 'route_code', 'shift_code', 'sequence', 'position_code'],
                'arm_breakdowns_lookup_unique'
            );
            $table->index(['route_code', 'shift_code']);
            $table->index('schedule_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_shift_breakdowns');
    }
};
