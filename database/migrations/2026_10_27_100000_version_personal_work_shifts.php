<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_time_month_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->json('settings');
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('calculation_version')->default(1);
            $table->uuid('operation_id')->nullable();
            $table->string('operation_hash', 64)->nullable();
            $table->string('shift_code', 8)->nullable();
            $table->json('segments')->nullable();
            $table->json('calculation_snapshot')->nullable();
            $table->json('calculation_result')->nullable();
            $table->unique(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'operation_id']);
            $table->dropColumn(['calculation_version', 'operation_id', 'operation_hash', 'shift_code', 'segments', 'calculation_snapshot', 'calculation_result']);
        });
        Schema::dropIfExists('work_time_month_settings');
    }
};
