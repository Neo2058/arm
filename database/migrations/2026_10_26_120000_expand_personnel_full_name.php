<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arm_personnel', fn (Blueprint $table) => $table->string('full_name', 255)->change());
    }

    public function down(): void
    {
        Schema::table('arm_personnel', fn (Blueprint $table) => $table->string('full_name', 40)->change());
    }
};
