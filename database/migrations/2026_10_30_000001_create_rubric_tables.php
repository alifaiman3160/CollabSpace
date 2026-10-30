<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marking rubrics, per subject and versioned.
 *
 * A rubric is never edited once saved. Re-uploading creates version n+1 and
 * leaves every earlier version exactly as it was, so marks approved against an
 * older version keep pointing at the levels they were given against.
 *
 *   rubrics          one version of a subject's rubric
 *   rubric_sections  a table in the file (e.g. a CLO), with how it is marked
 *   rubric_criteria  a row of that table, with its weight
 *   rubric_levels    a performance level of a criterion, with its descriptor
 *
 * "mode" is who decides a section or criterion: 'suggested' (the system may
 * offer a level from recorded activity) or 'manual' (the lecturer alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->string('source_file')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subject_id', 'version']);
        });

        Schema::create('rubric_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->string('mode', 12)->default('manual');
        });

        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_section_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->decimal('weight', 8, 2)->nullable();
            $table->string('mode', 12)->default('manual');
        });

        Schema::create('rubric_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_criterion_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 60);
            // The marks used in arithmetic: the upper bound when the file gave a range.
            $table->decimal('marks', 8, 2);
            // How the file wrote it when it was a range ("4–5"); null for a single figure.
            $table->string('marks_range', 20)->nullable();
            $table->text('descriptor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_levels');
        Schema::dropIfExists('rubric_criteria');
        Schema::dropIfExists('rubric_sections');
        Schema::dropIfExists('rubrics');
    }
};
