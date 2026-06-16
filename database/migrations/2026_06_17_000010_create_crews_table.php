<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crews', function (Blueprint $table) {
            $table->id();
            $table->string('member1_tab');   // табельный первого (270012)
            $table->string('member2_tab');   // табельный второго
            $table->enum('type', ['t5', 't6'])->default('t6');
            $table->string('label')->nullable(); // "270012 - 270011"
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member1_tab', 'member2_tab']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crews');
    }
};
