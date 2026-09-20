<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arm_personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('tab_number', 8)->unique();
            $table->string('full_name', 40);
            $table->date('hired_on')->nullable();
            $table->date('fired_on')->nullable();
            $table->string('position_code', 8)->nullable();
            $table->string('class_code', 4)->nullable();
            $table->string('phone_primary', 16)->nullable();
            $table->string('phone_secondary', 16)->nullable();
            $table->date('seniority_on')->nullable();
            $table->string('brigade_code', 8)->nullable();
            $table->boolean('is_brigadier')->default(false);
            $table->string('shop_code', 8)->nullable();
            $table->date('med_from')->nullable();
            $table->date('med_to')->nullable();
            $table->date('assistant_seniority_on')->nullable();
            $table->string('roster_number', 8)->nullable();
            $table->unsignedSmallInteger('assistant_seniority_years')->default(0);
            $table->json('early_windows')->nullable();
            $table->json('late_windows')->nullable();
            $table->date('brigadier_from')->nullable();
            $table->date('brigadier_to')->nullable();
            $table->string('category_code', 4)->nullable();
            $table->string('premium_flag', 4)->nullable();
            $table->string('main_tab_number', 8)->nullable();
            $table->string('depo_code', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_personnel');
    }
};
