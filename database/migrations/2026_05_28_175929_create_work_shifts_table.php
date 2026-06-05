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
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->date('shift_date')->comment('Дата смены (для календаря)');

            // Время и место начала
            $table->dateTime('started_at')->nullable()->comment('Время явки (дата + время)');
            $table->string('start_location')->nullable()->comment('Станция/пункт явки');

            // Время и место окончания
            $table->dateTime('ended_at')->nullable()->comment('Время сдачи (дата + время)');
            $table->string('end_location')->nullable()->comment('Станция/пункт сдачи');

            // Перерывы и отдых
            $table->integer('break_duration')->default(0)->comment('Продолжительность перерыва в минутах');

            // Итоговые расчетные данные (чтобы не пересчитывать каждый раз)
            $table->integer('total_minutes')->default(0)->comment('Всего отработано минут за смену');
            $table->decimal('estimated_earnings', 10, 2)->default(0.00)->comment('Предварительный расчет оплаты');

            $table->timestamps();

            // Индекс для быстрой выборки смен пользователя за конкретный месяц
            $table->index(['user_id', 'shift_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_shifts');
    }
};
