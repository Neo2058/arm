<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_training_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $t->string('column', 32);
            $t->date('planning_month');
            $t->string('shift_type', 32);
            $t->unsignedInteger('participant_count');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['instructor_id', 'planning_month']);
        });
        Schema::create('instructor_training_members', function (Blueprint $t) {
            $t->foreignId('request_id')->constrained('instructor_training_requests')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->primary(['request_id', 'user_id']);
        });
        Schema::create('instructor_training_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('operation_id');
            $t->string('column', 32);
            $t->char('fingerprint', 64);
            $t->json('result');
            $t->timestamp('created_at');
            $t->unique(['instructor_id', 'operation_id'], 'training_operations_owner_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_training_operations');
        Schema::dropIfExists('instructor_training_members');
        Schema::dropIfExists('instructor_training_requests');
    }
};
