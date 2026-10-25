<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mobile_enrolled_at')->nullable();
        });

        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('title', 120);
            $table->string('column', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('chat_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('source', 16)->default('regular');
            $table->date('window_starts_on')->nullable();
            $table->date('window_ends_on')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('chat_rooms')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_id')->nullable();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->string('type', 16);
            $table->string('status', 16)->default('available');
            $table->text('body_ciphertext')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['room_id', 'id']);
            $table->index(['room_id', 'status', 'id']);
        });

        DB::statement('CREATE UNIQUE INDEX chat_messages_room_sender_client_unique ON chat_messages (room_id, sender_id, client_id) WHERE client_id IS NOT NULL');

        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('chat_messages')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime');
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();
        });

        Schema::create('column_deputies', function (Blueprint $table) {
            $table->id();
            $table->string('column', 255);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->index(['column', 'user_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_members');
        Schema::dropIfExists('chat_rooms');
        Schema::dropIfExists('column_deputies');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mobile_enrolled_at');
        });
    }
};
