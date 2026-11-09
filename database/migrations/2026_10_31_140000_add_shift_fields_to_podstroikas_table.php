<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('podstroikas')) {
            Schema::table('podstroikas', function (Blueprint $table) {
                if (!Schema::hasColumn('podstroikas', 'target_date')) {
                    $table->date('target_date')->nullable()->after('for_month');
                }
                if (!Schema::hasColumn('podstroikas', 'route_code')) {
                    $table->string('route_code', 20)->nullable()->after('target_date');
                }
                if (!Schema::hasColumn('podstroikas', 'shift_code')) {
                    $table->string('shift_code', 10)->nullable()->after('route_code');
                }
                if (!Schema::hasColumn('podstroikas', 'source')) {
                    $table->string('source', 20)->default('web')->after('status');
                }
                if (!Schema::hasColumn('podstroikas', 'conflict_reason')) {
                    $table->text('conflict_reason')->nullable()->after('source');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('podstroikas')) {
            Schema::table('podstroikas', function (Blueprint $table) {
                $columns = ['target_date', 'route_code', 'shift_code', 'source', 'conflict_reason'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('podstroikas', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
