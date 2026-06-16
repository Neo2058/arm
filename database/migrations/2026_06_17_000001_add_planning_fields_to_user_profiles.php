<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->boolean('is_brigadir')->default(false);
            $table->boolean('can_manage_t6')->default(false); // управление составом типа т6
            $table->boolean('can_maneuvers')->default(false); // возможность производить манёвры М
            $table->boolean('is_pomoshnik')->default(false); // пометка П для помощника
            $table->string('additional_notes')->nullable(); // дополнительные данные для планирования
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_brigadir', 'can_manage_t6', 'can_maneuvers', 'is_pomoshnik', 'additional_notes']);
        });
    }
};
