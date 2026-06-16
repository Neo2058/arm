<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_formular_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('formular_task_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->text('entry_text')->nullable(); // what the user wrote in the formular
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'formular_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_formular_logs');
    }
};
