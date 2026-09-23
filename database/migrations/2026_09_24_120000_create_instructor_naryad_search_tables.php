<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_naryad_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->date('naryad_date')->nullable();
            $table->string('weekday', 32)->nullable();
            $table->boolean('weekend')->default(false);
            $table->boolean('even_day')->default(false);
            $table->json('meta')->nullable();
            $table->json('assignments')->nullable();
            $table->timestamps();
        });

        Schema::create('instructor_shift_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('original_name');
            $table->string('path');
            $table->json('entries')->nullable();
            $table->timestamps();
        });

        Schema::create('instructor_query_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->json('queries')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_query_lists');
        Schema::dropIfExists('instructor_shift_tables');
        Schema::dropIfExists('instructor_naryad_files');
    }
};
