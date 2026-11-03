<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which assessments a shared file (one with no assessment of its own: "Course materials") is shown under. A shared file
 * with no row here is shown under every assessment, exactly as before, so nothing existing changes. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_file_assessment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_file_id')->constrained('subject_files')->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();

            $table->unique(['subject_file_id', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_file_assessment');
    }
};
