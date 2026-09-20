<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arm_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tab_number', 8);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('kind_code', 8);
            $table->timestamps();

            $table->unique(['tab_number', 'starts_on', 'ends_on', 'kind_code'], 'arm_absences_lookup_unique');
            $table->index(['starts_on', 'ends_on']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_absences');
    }
};
