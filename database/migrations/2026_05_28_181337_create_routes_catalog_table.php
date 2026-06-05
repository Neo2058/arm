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
        Schema::create('routes_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('route_number')->comment('Номер/название маршрута (например: 104-Экспресс)');
            $table->string('start_location')->comment('Станция явки');
            $table->time('default_start_time')->comment('Стандартное время явки');
            $table->string('end_location')->comment('Станция сдачи');
            $table->time('default_end_time')->comment('Стандартное время сдачи');
            $table->integer('default_break_duration')->default(0)->comment('Стандартный перерыв в минутах');
            $table->text('technological_tasks')->nullable()->comment('План работы: регламент, опробование тормозов, ТО-1 и т.д.');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routes_catalog');
    }
};
