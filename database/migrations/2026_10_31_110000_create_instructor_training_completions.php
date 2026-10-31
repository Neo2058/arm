<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_training_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->unique()->constrained('instructor_training_requests')->restrictOnDelete();
            $t->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $t->string('column', 32);
            $t->string('normative_type', 16);
            $t->date('performed_on');
            $t->string('shift_type', 32);
            $t->unsignedInteger('period_months');
            $t->timestamp('created_at');
            $t->index(['instructor_id', 'performed_on']);
        });
        Schema::create('instructor_training_attendance', function (Blueprint $t) {
            $t->foreignId('completion_id')->constrained('instructor_training_completions')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('name');
            $t->string('tab_number')->nullable();
            $t->primary(['completion_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_training_attendance');
        Schema::dropIfExists('instructor_training_completions');
    }
};
