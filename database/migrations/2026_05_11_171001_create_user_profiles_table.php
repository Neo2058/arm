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
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('tab_number')->nullable()->comment('Табельный номер');
            $table->string('column')->nullable()->comment('Номер колонны');
            $table->string('instructor')->nullable()->comment('ФИО инструктора');
            $table->string('position')->nullable()->comment('Должность (подробно)');
            $table->string('phoneNumber')->nullable()->comment('Телефон');
            $table->string('driverRoot')->nullable()->comment('Номер прав');
            $table->date('dateRoot')->nullable()->comment('Дата получения прав');
            $table->date('birth_date')->nullable()->comment('Дата рождения');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
