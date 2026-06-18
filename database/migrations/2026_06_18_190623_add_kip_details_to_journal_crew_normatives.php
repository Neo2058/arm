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
        Schema::table('journal_crew_normatives', function (Blueprint $table) {
            $table->time('start_time')->nullable();
            $table->string('start_station')->nullable();
            $table->time('end_time')->nullable();
            $table->string('end_station')->nullable();
            $table->text('remarks')->nullable();
            $table->json('occurrences')->nullable(); // for multiple KIPs: [{start_time, start_station, end_time, end_station}, ...]
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_crew_normatives', function (Blueprint $table) {
            //
        });
    }
};
