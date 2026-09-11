<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('group_size')->default(4);
            $table->timestamps();

            $table->unique(['subject_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
