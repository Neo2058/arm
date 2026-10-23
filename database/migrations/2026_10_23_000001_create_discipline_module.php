<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text = ANY (ARRAY['admin','instructor','student','driver','dispatcher','super_admin','operator','tormoznik','nachalnik']::text[]))");
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->sqliteRoles(['admin', 'instructor', 'student', 'driver', 'dispatcher', 'super_admin', 'operator', 'tormoznik', 'nachalnik']);
        } else {
            throw new RuntimeException('Discipline supports PostgreSQL and SQLite.');
        }
        Schema::create('discipline_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('title');
            $t->text('instructions');
            $t->date('due_date');
            $t->boolean('require_response')->default(true);
            $t->boolean('require_report_file')->default(false);
            $t->boolean('require_explanation_file')->default(false);
            $t->timestamps();
        });
        Schema::create('discipline_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('card_id')->constrained('discipline_cards')->restrictOnDelete();
            $t->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $t->string('column_snapshot', 100)->nullable();
            $t->enum('status', ['assigned', 'in_review', 'needs_revision', 'accepted'])->default('assigned');
            $t->unsignedBigInteger('version')->default(1);
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->unique(['card_id', 'assignee_id']);
            $t->index(['assignee_id', 'status']);
        });
        Schema::create('discipline_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('discipline_assignments')->restrictOnDelete();
            $t->unsignedInteger('attempt');
            $t->text('response');
            $t->json('requirements');
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at');
            $t->enum('decision', ['accepted', 'returned'])->nullable();
            $t->text('review_comment')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->unique(['assignment_id', 'attempt']);
        });
        Schema::create('discipline_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('report_id')->constrained('discipline_reports')->restrictOnDelete();
            $t->enum('category', ['report', 'explanation', 'other']);
            $t->string('name');
            $t->string('path')->unique();
            $t->string('mime', 100);
            $t->unsignedInteger('size');
            $t->string('sha256', 64);
            $t->timestamp('created_at');
        });
        Schema::create('discipline_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('operation_id');
            $t->string('fingerprint', 64);
            $t->json('result');
            $t->timestamp('created_at');
            $t->unique(['user_id', 'operation_id']);
        });
        Schema::create('discipline_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assignment_id')->constrained('discipline_assignments')->restrictOnDelete();
            $t->string('event');
            $t->timestamp('created_at');
            $t->timestamp('read_at')->nullable();
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        // Refuse a lossy rollback while accounts use the new roles.
        if (DB::table('users')->whereIn('role', ['tormoznik', 'nachalnik'])->exists()) {
            throw new RuntimeException('Reassign discipline roles before rolling back.');
        }
        foreach (['discipline_notifications', 'discipline_operations', 'discipline_files', 'discipline_reports', 'discipline_assignments', 'discipline_cards'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text = ANY (ARRAY['admin','instructor','student','driver','dispatcher','super_admin','operator']::text[]))");
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->sqliteRoles(['admin', 'instructor', 'student', 'driver', 'dispatcher', 'super_admin', 'operator']);
        }
    }

    private function sqliteRoles(array $roles): void
    {
        if (version_compare(DB::selectOne('select sqlite_version() as version')->version, '3.35', '<')) {
            throw new RuntimeException('SQLite 3.35+ is required for a non-destructive role migration.');
        }
        // change() rebuilds the parent users table; with active FK constraints that can
        // cascade-delete personal data. Native column operations preserve the table.
        Schema::table('users', fn (Blueprint $t) => $t->enum('discipline_role_transition', $roles)->default('student'));
        DB::table('users')->update(['discipline_role_transition' => DB::raw('role')]);
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
        Schema::table('users', fn (Blueprint $t) => $t->renameColumn('discipline_role_transition', 'role'));
    }
};
