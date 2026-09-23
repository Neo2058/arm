<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('naryad_norms', function (Blueprint $table) {
            $table->json('planir')->nullable();
        });

        Schema::create('arm_day_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tab_number', 8);
            $table->date('plan_date');
            $table->string('route_code', 8)->default('');
            $table->string('shift_code', 8)->default('');
            $table->timestamps();

            $table->unique(['tab_number', 'plan_date'], 'arm_day_adjustments_lookup_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_day_adjustments');
        Schema::table('naryad_norms', function (Blueprint $table) {
            $table->dropColumn('planir');
        });
    }
};
