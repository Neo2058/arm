<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_topic_id')->constrained('training_topics')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('text'); // text, video, audio, document, quiz
            $table->longText('content')->nullable();     // текстовая выжимка
            $table->string('file_path')->nullable();     // путь в MinIO/S3
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('duration')->nullable(); // секунды
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();        // доп. данные (например, внешняя ссылка)
            $table->timestamps();

            $table->index(['training_topic_id', 'is_active', 'sort_order']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_materials');
    }
};
