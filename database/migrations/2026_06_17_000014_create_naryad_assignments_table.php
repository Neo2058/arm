<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('naryad_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('plan_date');
            $table->string('route_number');                 // финальный номер, проставленный нарядчиком
            $table->foreignId('crew_id')->nullable()->constrained('crews')->nullOnDelete();
            $table->string('position')->nullable();         // driver / pomoshnik
            $table->string('start_location')->nullable();
            $table->time('start_time')->nullable();
            $table->string('end_location')->nullable();
            $table->time('end_time')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'plan_date']);
            $table->index(['plan_date', 'route_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('naryad_assignments');
    }
};
