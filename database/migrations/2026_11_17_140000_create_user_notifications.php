<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('resource_type', 32)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('title', 191);
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'id']);
            $table->index(['user_id', 'type', 'resource_id']);
        });

        DB::statement("CREATE UNIQUE INDEX user_notifications_unread_chat_unique ON user_notifications (user_id, type, resource_id) WHERE read_at IS NULL AND type = 'chat_message'");

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('endpoint', 512);
            $table->string('public_key', 191)->nullable();
            $table->string('auth_token', 191)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('user_notifications');
    }
};
