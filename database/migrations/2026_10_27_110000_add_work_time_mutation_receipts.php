<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['work_shifts', 'work_time_month_settings'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedInteger('revision')->default(1));
        }
        Schema::create('work_time_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('action', 100);
            $table->string('payload_hash', 64);
            $table->json('response');
            $table->timestamps();
            $table->unique(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_time_mutations');
        foreach (['work_shifts', 'work_time_month_settings'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('revision'));
        }
    }
};
