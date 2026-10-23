<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_password_resets', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_account_id')->constrained('telegram_accounts')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_password_resets');
    }
};
