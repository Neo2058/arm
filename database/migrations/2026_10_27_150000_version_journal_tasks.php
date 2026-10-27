<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_tasks', fn (Blueprint $t) => $t->unsignedBigInteger('version')->default(1));
        Schema::create('journal_task_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('operation_id');
            $t->string('column')->nullable();
            $t->string('fingerprint', 64);
            $t->json('result');
            $t->timestamp('created_at');
            $t->unique(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('journal_task_operations')->exists()) {
            throw new RuntimeException('Committed journal operations must not be discarded by schema rollback.');
        }
        Schema::dropIfExists('journal_task_operations');
        Schema::table('journal_tasks', fn (Blueprint $t) => $t->dropColumn('version'));
    }
};
