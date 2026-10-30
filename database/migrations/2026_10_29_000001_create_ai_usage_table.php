<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many AI requests each lecturer has made today.
 *
 * A count and nothing else. What was asked and what came back is not kept here,
 * and no API key is ever stored: keys live in .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('requests')->default(0);

            $table->unique(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
