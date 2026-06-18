<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('journal_crew_normatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // the crew member
            $table->foreignId('instructor_id')->constrained('users')->onDelete('cascade');
            $table->string('column', 50)->nullable();
            $table->string('type'); // kip_linia, kip_manevry, kip_podem, etc.
            $table->date('last_date')->nullable();
            $table->date('next_date')->nullable();
            $table->string('class')->nullable(); // at the time of last
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_crew_normatives');
    }
};
