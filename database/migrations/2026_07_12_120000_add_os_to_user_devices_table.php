<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Added for device OS detection (ios / android / other) used by SecureDocumentViewer.
     */
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (!Schema::hasColumn('user_devices', 'os')) {
                $table->string('os')->default('other')->after('device_name')
                    ->comment('Operating system: ios, android, other (for viewer selection)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            if (Schema::hasColumn('user_devices', 'os')) {
                $table->dropColumn('os');
            }
        });
    }
};
