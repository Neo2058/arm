<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->restrictOnDelete();
            $table->timestamps();
            $table->index(['category', 'parent_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('document_folder_id')->nullable()->constrained('document_folders')->restrictOnDelete();
            $table->char('file_sha256', 64)->nullable()->index();
            $table->string('document_number')->nullable()->index();
            $table->unsignedSmallInteger('publication_year')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_folder_id');
            $table->dropColumn(['document_number', 'publication_year', 'file_sha256']);
        });
        Schema::dropIfExists('document_folders');
    }
};
