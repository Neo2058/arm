<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('naryad_norms', function (Blueprint $table) {
            $table->id();
            $table->integer('year_hours')->default(2000); // default example
            $table->integer('month_hours')->default(166);
            $table->integer('week_hours')->default(40); // 6/1 week ~40-48?
            $table->integer('min_rest_hours')->default(8); // min hours between shifts
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('naryad_norms');
    }
};
