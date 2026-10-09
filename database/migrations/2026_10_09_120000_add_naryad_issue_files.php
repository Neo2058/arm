<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('naryads', function (Blueprint $table) {
            $table->json('source_files')->nullable()->after('file_path');
            $table->string('work_shifts_path')->nullable()->after('source_files');
            $table->string('weekend_shifts_path')->nullable()->after('work_shifts_path');
        });

        Schema::create('naryad_person_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('naryad_id')->constrained('naryads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('fio');
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedInteger('hits_count')->default(0);
            $table->timestamps();

            $table->unique(['naryad_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('naryad_person_files');

        Schema::table('naryads', function (Blueprint $table) {
            $table->dropColumn(['source_files', 'work_shifts_path', 'weekend_shifts_path']);
        });
    }
};
