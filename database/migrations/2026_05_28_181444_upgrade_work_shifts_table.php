<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Используем table вместо create для модификации существующей таблицы!
        Schema::table('work_shifts', function (Blueprint $table) {
            // Добавляем внешнюю связь на каталог маршрутов
            $table->foreignId('route_id')->nullable()->after('user_id')->constrained('routes_catalog')->onDelete('set null');

            // Добавляем новые переключатели типа записи и отвлечений
            $table->enum('type', ['work', 'deviation'])->default('work')->after('route_id');
            $table->enum('deviation_type', ['sick', 'training', 'study_leave'])->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            // Откатываем изменения в обратном порядке
            $table->dropForeign(['route_id']);
            $table->dropColumn(['route_id', 'type', 'deviation_type', 'start_location', 'end_location']);
            $table->dropIndex(['user_id', 'shift_date']);
        });
    }
};
