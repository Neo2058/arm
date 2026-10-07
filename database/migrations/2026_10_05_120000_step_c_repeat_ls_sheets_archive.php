<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arm_accounts', function (Blueprint $table) {
            $table->dropUnique('arm_accounts_user_id_year_month_kind_unique');
        });
        Schema::table('arm_accounts', function (Blueprint $table) {
            $table->string('ls_number', 4)->default('0')->after('kind');
            $table->unique(['user_id', 'year_month', 'kind', 'ls_number'], 'arm_accounts_user_month_kind_ls_unique');
        });

        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->boolean('registered')->default(false)->after('two_person');
        });

        Schema::create('arm_uk_copies', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('plan_date');
            $table->string('route_number')->default('');
            $table->string('work_code', 8)->nullable();
            $table->string('shift_code', 8)->nullable();
            $table->boolean('registered')->default(false);
            $table->boolean('two_person')->default(false);
            $table->decimal('hours_total', 6, 3)->nullable();
            $table->decimal('hours_line', 6, 3)->nullable();
            $table->decimal('hours_reserve', 6, 3)->nullable();
            $table->decimal('hours_night', 6, 3)->nullable();
            $table->decimal('hours_night_reserve', 6, 3)->nullable();
            $table->decimal('hours_evening', 6, 3)->nullable();
            $table->decimal('hours_evening_reserve', 6, 3)->nullable();
            $table->decimal('hours_break', 6, 3)->nullable();
            $table->decimal('hours_break_reserve', 6, 3)->nullable();
            $table->decimal('hours_holiday', 6, 3)->nullable();
            $table->decimal('hours_holiday_reserve', 6, 3)->nullable();
            $table->decimal('hours_overtime', 6, 3)->nullable();
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
            $table->decimal('hours_line_2_reserve', 6, 3)->nullable();
            $table->timestamps();
            $table->unique(['year_month', 'user_id', 'plan_date']);
            $table->index(['year_month', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_uk_copies');
        Schema::table('naryad_assignments', function (Blueprint $table) {
            $table->dropColumn('registered');
        });
        Schema::table('arm_accounts', function (Blueprint $table) {
            $table->dropUnique('arm_accounts_user_month_kind_ls_unique');
        });
        Schema::table('arm_accounts', function (Blueprint $table) {
            $table->dropColumn('ls_number');
            $table->unique(['user_id', 'year_month', 'kind']);
        });
    }
};
