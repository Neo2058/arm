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
        Schema::create('journal_normative_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('column', 50)->nullable();

            // КИП Линия
            $table->integer('kip_linia_bk_months')->default(4);
            $table->integer('kip_linia_3_months')->default(3);
            $table->integer('kip_linia_2_months')->default(4);
            $table->integer('kip_linia_1_months')->default(4);
            $table->integer('kip_linia_add_months')->default(4); // 1 or 4

            // КИП Манёвры
            $table->integer('kip_manevry_months')->default(6);
            $table->boolean('kip_manevry_alternation')->default(true);

            // КИП Подъём
            $table->integer('kip_podem_months')->default(12); // free, default example

            // Others - free
            $table->integer('kip_kru_months')->default(12);
            $table->integer('kip_ars_r_months')->default(12);
            $table->integer('kip_pnevmatika_months')->default(12);
            $table->integer('kip_scep_months')->default(12);
            $table->integer('atz_months')->default(12);
            $table->integer('atz_line_months')->default(12);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_normative_settings');
    }
};
