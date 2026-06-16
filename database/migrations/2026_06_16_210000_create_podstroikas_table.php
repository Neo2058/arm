<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('podstroikas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('for_month'); // first day of the month for which the adjustment is requested
            $table->text('details'); // description of the shift adjustment request
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['user_id', 'for_month']); // one adjustment per user per month
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('podstroikas');
    }
};
