<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->decimal('hours_total_2', 6, 3)->nullable();
            $table->decimal('hours_line_2', 6, 3)->nullable();
            $table->decimal('hours_reserve_2', 6, 3)->nullable();
            $table->decimal('hours_night_2', 6, 3)->nullable();
            $table->decimal('hours_night_2_reserve', 6, 3)->nullable();
            $table->decimal('hours_evening_2', 6, 3)->nullable();
            $table->decimal('hours_evening_2_reserve', 6, 3)->nullable();
            $table->decimal('hours_break_2', 6, 3)->nullable();
            $table->decimal('hours_break_2_reserve', 6, 3)->nullable();
            $table->decimal('hours_holiday_2', 6, 3)->nullable();
            $table->decimal('hours_holiday_2_reserve', 6, 3)->nullable();
        });

        Schema::create('arm_pay_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16); // machinist | assistant
            $table->unsignedSmallInteger('nom');
            $table->string('name', 40);
            $table->string('percent_text', 8)->nullable();
            $table->decimal('percent', 8, 2)->default(100);
            $table->string('pay_code', 8)->nullable();
            $table->string('tariff_code', 8)->nullable();
            $table->decimal('tariff', 12, 3)->default(0);
            $table->string('cost_code', 12)->nullable();
            $table->string('formula', 400)->nullable();
            $table->boolean('selected')->default(true);
            $table->timestamps();
            $table->unique(['kind', 'nom']);
        });

        Schema::create('arm_periods', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7)->unique();
            $table->string('status', 16)->default('open'); // open | closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('arm_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7);
            $table->string('kind', 16)->default('primary'); // primary | repeat
            $table->string('position_code', 8)->nullable();
            $table->string('formula_kind', 16)->nullable();
            $table->json('totals')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->unique(['user_id', 'year_month', 'kind']);
            $table->index('year_month');
        });

        Schema::create('arm_account_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arm_account_id')->constrained('arm_accounts')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('name', 40);
            $table->string('pay_code', 8)->nullable();
            $table->string('tariff_code', 8)->nullable();
            $table->decimal('tariff', 12, 3)->default(0);
            $table->decimal('percent', 8, 2)->default(100);
            $table->decimal('hours', 10, 3)->default(0);
            $table->string('cost_code', 12)->nullable();
            $table->string('profession', 8)->nullable();
            $table->string('ls_number', 4)->default('0');
            $table->foreignId('formula_id')->nullable()->constrained('arm_pay_formulas')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_account_lines');
        Schema::dropIfExists('arm_accounts');
        Schema::dropIfExists('arm_periods');
        Schema::dropIfExists('arm_pay_formulas');
        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'hours_total_2',
                'hours_line_2',
                'hours_reserve_2',
                'hours_night_2',
                'hours_night_2_reserve',
                'hours_evening_2',
                'hours_evening_2_reserve',
                'hours_break_2',
                'hours_break_2_reserve',
                'hours_holiday_2',
                'hours_holiday_2_reserve',
            ]);
        });
    }
};
