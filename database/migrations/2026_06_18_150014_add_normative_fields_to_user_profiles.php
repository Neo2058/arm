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
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('normative_class')->nullable(); // bk, 3, 2, 1
            $table->boolean('is_maneuver')->default(false);
            $table->boolean('is_t6')->default(false);
            // is_pomoshnik already exists from earlier migration
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumnIfExists('normative_class');
            $table->dropColumnIfExists('is_maneuver');
            $table->dropColumnIfExists('is_t6');
        });
    }
};
