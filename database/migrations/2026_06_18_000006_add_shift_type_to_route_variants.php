<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('route_variants', 'shift_type')) {
                $table->string('shift_type', 10)
                    ->nullable()
                    ->after('schedule_type_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('route_variants', function (Blueprint $table) {
            if (Schema::hasColumn('route_variants', 'shift_type')) {
                $table->dropColumn('shift_type');
            }
        });
    }
};
