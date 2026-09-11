<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->longText('content')->nullable();
            $table->timestamps();

            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
