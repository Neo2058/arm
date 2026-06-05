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
        Schema::create('teaching', function (Blueprint $table) {
            $table->id();
            $table->string('title');        // Название для пользователя
            $table->string('file_path');    // Путь к файлу в MinIO
            $table->string('category')->nullable(); // Например: "Приказ", "Инструкция", "Распоряжение"
            $table->integer('size')->nullable();    // Размер файла
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teaching');
    }
};
