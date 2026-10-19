<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->boolean('is_violation')->default(false)->after('action');
            $table->index('is_violation');
        });

        DB::table('action_logs')->where('action', 'like', 'violation%')->update(['is_violation' => true]);
    }

    public function down(): void
    {
        Schema::table('action_logs', function (Blueprint $table) {
            $table->dropIndex(['is_violation']);
            $table->dropColumn('is_violation');
        });
    }
};
