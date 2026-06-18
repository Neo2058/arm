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
        Schema::create('journal_vacations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // crew member
            $table->foreignId('instructor_id')->constrained('users')->onDelete('cascade');
            $table->string('column', 50)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type')->nullable()->default('основной'); // основной, учебный и т.д. (опционально)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['column', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_vacations');
    }
};
