<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('naryad_quotas', function (Blueprint $table) {
            $table->id();
            $table->date('plan_date')->unique();
            $table->foreignId('schedule_type_id')->nullable()->constrained('schedule_types')->nullOnDelete();
            $table->unsignedSmallInteger('required_crews')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('naryad_quotas');
    }
};
