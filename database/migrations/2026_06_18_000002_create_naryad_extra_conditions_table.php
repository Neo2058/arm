<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('naryad_extra_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('naryad_norm_id')->constrained('naryad_norms')->cascadeOnDelete();
            $table->string('name'); // e.g. "max_consecutive_days"
            $table->string('value'); // e.g. "5"
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('naryad_extra_conditions');
    }
};
