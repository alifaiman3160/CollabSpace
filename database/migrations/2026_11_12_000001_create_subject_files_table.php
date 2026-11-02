<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Files a lecturer attaches to a subject (the project question, a marking guide): kept on the private disk under
 * subject-files/{subject_id}/ with a random stored name, served only through an authorised route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);                 // question | marking_guide | other
            $table->string('title', 120);
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_files');
    }
};
