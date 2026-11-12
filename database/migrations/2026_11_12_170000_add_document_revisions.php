<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('root_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('replaces_document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->string('revision_label')->default('1');
            $table->date('effective_on')->nullable();
            $table->timestamp('withdrawn_at')->nullable()->index();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('withdrawal_reason')->nullable();
        });
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX documents_one_active_revision ON documents (COALESCE(root_document_id, id)) WHERE withdrawn_at IS NULL');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX documents_one_active_revision');
        }
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('root_document_id');
            $table->dropConstrainedForeignId('replaces_document_id');
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn(['revision_number', 'revision_label', 'effective_on', 'withdrawn_at', 'withdrawal_reason']);
        });
    }
};
