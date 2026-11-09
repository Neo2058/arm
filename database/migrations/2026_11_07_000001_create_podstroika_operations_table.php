<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('podstroika_operations')) {
            Schema::create('podstroika_operations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('operation_id');
                $table->char('payload_hash', 64);
                $table->json('response');
                $table->timestamps();

                $table->unique(['user_id', 'operation_id'], 'podstroika_operations_user_uuid_unique');
                $table->index(['user_id', 'created_at']);
            });
        }

        if (Schema::hasTable('podstroikas') && !Schema::hasColumn('podstroikas', 'operation_id')) {
            Schema::table('podstroikas', function (Blueprint $table) {
                $table->uuid('operation_id')->nullable()->after('conflict_reason');
                $table->index('operation_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('podstroika_operations');
        if (Schema::hasTable('podstroikas') && Schema::hasColumn('podstroikas', 'operation_id')) {
            Schema::table('podstroikas', function (Blueprint $table) {
                $table->dropColumn('operation_id');
            });
        }
    }
};
