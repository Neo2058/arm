<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arm_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tab_number', 8);
            $table->date('appointed_on');
            $table->string('position_code', 8)->default('');
            $table->string('class_code', 4)->nullable();
            $table->timestamps();

            $table->unique(['tab_number', 'appointed_on', 'position_code'], 'arm_appointments_lookup_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_appointments');
    }
};
