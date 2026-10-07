<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arm_code_books', function (Blueprint $table) {
            $table->id();
            $table->string('kodspr', 2)->unique();
            $table->string('dbfspr', 8)->default('ELKODIF');
            $table->string('nsisr', 1)->nullable();
            $table->string('nazspr', 30);
            $table->unsignedTinyInteger('dlkodel')->default(2);
            $table->unsignedTinyInteger('dlnazel')->default(30);
            $table->timestamps();
        });

        Schema::create('arm_codes', function (Blueprint $table) {
            $table->id();
            $table->string('book_code', 2);
            $table->string('code', 8);
            $table->string('name', 60)->default('');
            $table->string('nom', 5)->default('');
            $table->timestamps();
            $table->unique(['book_code', 'code', 'nom']);
            $table->index('book_code');
        });

        Schema::create('arm_signatures', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('sort_order')->default(0);
            $table->string('position', 45);
            $table->string('full_name', 18)->default('');
            $table->timestamps();
        });

        Schema::create('arm_rosters', function (Blueprint $table) {
            $table->id();
            $table->date('roster_date');
            $table->string('kind', 16)->default('primary');
            $table->string('route_number', 3);
            $table->string('instrument', 4)->nullable();
            $table->timestamps();
            $table->unique(['roster_date', 'kind', 'route_number']);
            $table->index('roster_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arm_rosters');
        Schema::dropIfExists('arm_signatures');
        Schema::dropIfExists('arm_codes');
        Schema::dropIfExists('arm_code_books');
    }
};
