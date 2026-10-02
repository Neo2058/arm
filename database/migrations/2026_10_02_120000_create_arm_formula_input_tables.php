<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arm_pay_formulas', function (Blueprint $table) {
            $table->string('percent_source', 16)->nullable()->after('percent_text');
        });

        Schema::create('arm_month_norms', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7)->unique();
            $table->decimal('month_hours', 8, 3)->default(0);
            $table->decimal('day_hours', 8, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('arm_premium_rates', function (Blueprint $table) {
            $table->id();
            $table->string('position_code', 8);
            $table->boolean('is_brigadier')->default(false);
            $table->decimal('percent', 8, 2)->default(0);
            $table->string('name', 40)->nullable();
            $table->timestamps();
            $table->unique(['position_code', 'is_brigadier']);
        });

        Schema::create('arm_seniority_bands', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('years_from');
            $table->unsignedTinyInteger('years_to');
            $table->decimal('percent', 8, 2)->default(0);
            $table->timestamps();
            $table->unique(['years_from', 'years_to']);
        });

        Schema::create('arm_extra_pays', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tab_number', 8);
            $table->string('full_name', 40)->nullable();
            $table->string('position_code', 8)->nullable();
            $table->decimal('hours_tech', 6, 2)->default(0);
            $table->date('tech_on')->nullable();
            $table->decimal('hours_accident', 6, 2)->default(0);
            $table->date('accident_on')->nullable();
            $table->decimal('hours_med', 6, 2)->default(0);
            $table->date('med_on')->nullable();
            $table->unsignedTinyInteger('extra_days_off')->default(0);
            $table->decimal('extra_hours_off', 6, 2)->default(0);
            $table->timestamps();
            $table->unique(['year_month', 'tab_number']);
            $table->index('user_id');
        });

        Schema::create('arm_month_premiums', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tab_number', 8);
            $table->string('position_code', 8)->nullable();
            $table->decimal('percent_plan', 8, 2)->default(0);
            $table->decimal('percent_fact', 8, 2)->default(0);
            $table->decimal('ktu', 4, 2)->default(1);
            $table->string('note', 40)->nullable();
            $table->timestamps();
            $table->unique(['year_month', 'tab_number', 'position_code']);
            $table->index('user_id');
        });

        Schema::create('arm_tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 80);
            $table->decimal('rate', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('arm_pay_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_pay_kinds');
        Schema::dropIfExists('arm_tariffs');
        Schema::dropIfExists('arm_month_premiums');
        Schema::dropIfExists('arm_extra_pays');
        Schema::dropIfExists('arm_seniority_bands');
        Schema::dropIfExists('arm_premium_rates');
        Schema::dropIfExists('arm_month_norms');
        Schema::table('arm_pay_formulas', function (Blueprint $table) {
            $table->dropColumn('percent_source');
        });
    }
};
