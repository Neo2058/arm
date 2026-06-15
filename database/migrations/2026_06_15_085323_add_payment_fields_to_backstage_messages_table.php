<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backstage_messages', function (Blueprint $table) {
            $table->string('yookassa_payment_id')->nullable()->after('support_amount');
            $table->string('payment_status')->default('pending')->after('yookassa_payment_id'); // pending, waiting_for_capture, succeeded, canceled
            $table->timestamp('paid_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backstage_messages', function (Blueprint $table) {
            $table->dropColumn(['yookassa_payment_id', 'payment_status', 'paid_at']);
        });
    }
};
