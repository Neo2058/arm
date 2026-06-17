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
        Schema::create('deviations_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Название отвлечения (например: Больничный лист)');
            $table->string('sys_key')->unique()->comment('Системный ключ (например: sick, training)');
            $table->string('short_code', 10)->nullable()->after('sys_key')->comment('Короткий код для статистики (Б, В и т.д.)');
            $table->decimal('hourly_rate', 10, 2)->default(0.00)->comment('Часовая тарифная ставка для этого отвлечения (руб/час)');
            $table->integer('default_minutes')->default(480)->comment('Стандартное время за день (по умолчанию 480 мин = 8ч)');
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deviations_catalogs');
    }
};
