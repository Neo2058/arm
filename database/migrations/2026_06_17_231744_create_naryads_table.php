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
        Schema::create('naryads', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('file_path'); // s3 path
            $table->date('naryad_date')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->json('allowed_roles')->nullable(); // like documents
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('naryads');
    }
};
