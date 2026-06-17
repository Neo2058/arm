<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_variants', function (Blueprint $table) {
            $table->id();
            $table->string('effective_route');                    // что нарядчик проставит (25, 36...)
            $table->enum('context', ['morning', 'night', 'any'])->default('any');
            $table->string('base_route_number')->nullable();
            $table->foreignId('route_catalog_id')->nullable()->constrained('routes_catalog')->nullOnDelete();
            $table->foreignId('schedule_type_id')->nullable()->constrained('schedule_types')->nullOnDelete();
            $table->string('start_location')->nullable();
            $table->time('start_time')->nullable();
            $table->string('end_location')->nullable();
            $table->time('end_time')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['effective_route', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_variants');
    }
};
