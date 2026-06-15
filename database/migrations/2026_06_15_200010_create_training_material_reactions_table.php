<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_material_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_material_id')->constrained('training_materials')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('reaction_type', ['like', 'dislike']);
            $table->timestamps();

            $table->unique(['training_material_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_material_reactions');
    }
};
