<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('route_variants', 'schedule_type_id')) {
                $table->foreignId('schedule_type_id')
                    ->nullable()
                    ->after('route_catalog_id')
                    ->constrained('schedule_types')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('route_variants', function (Blueprint $table) {
            if (Schema::hasColumn('route_variants', 'schedule_type_id')) {
                $table->dropForeign(['schedule_type_id']);
                $table->dropColumn('schedule_type_id');
            }
        });
    }
};
