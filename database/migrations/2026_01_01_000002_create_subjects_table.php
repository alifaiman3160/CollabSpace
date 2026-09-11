<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->unsignedTinyInteger('group_size')->default(4);
            $table->timestamps();

            $table->unique(['lecturer_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
