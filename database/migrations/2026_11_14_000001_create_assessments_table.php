<?php

use App\Support\Assessments\Backfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Several assessments per subject (Assignment 1, Assignment 2, Project), each with its own dates and its own marking
 * rubric. The groups stay per subject. A rubric now belongs to one assessment, and its version numbering and "current"
 * flag are per assessment.
 *
 * Additive and safe to repeat: every existing subject gets ONE default assessment titled "Project" (due on the
 * subject's project deadline, no start date) and its existing rubrics move into it, so every approved mark, choice,
 * suggestion and frozen snapshot (all of which hang off a rubric) belongs to it. subjects.project_deadline is kept.
 * Take a backup first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assessments')) {
            Schema::create('assessments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
                $table->string('title', 80);
                $table->string('type', 12)->default('project');          // assignment | project
                $table->decimal('weight', 5, 2)->nullable();             // informational only, never used in a calculation
                $table->date('starts_on')->nullable();
                $table->date('due_on')->nullable();
                $table->unsignedSmallInteger('position')->default(1);
                $table->timestamps();

                $table->unique(['subject_id', 'title']);
            });
        }

        if (! Schema::hasColumn('rubrics', 'assessment_id')) {
            Schema::table('rubrics', function (Blueprint $table) {
                $table->foreignId('assessment_id')->nullable()->after('subject_id')->constrained('assessments')->cascadeOnDelete();
                // The subject foreign key keeps an index of its own once the composite unique below is gone.
                $table->index('subject_id', 'rubrics_subject_id_index');
            });

            Schema::table('rubrics', function (Blueprint $table) {
                $table->dropUnique(['subject_id', 'version']);
            });

            Schema::table('rubrics', function (Blueprint $table) {
                $table->unique(['assessment_id', 'version']);
            });
        }

        Backfill::run();
    }

    public function down(): void
    {
        if (Schema::hasColumn('rubrics', 'assessment_id')) {
            Schema::table('rubrics', function (Blueprint $table) {
                $table->dropUnique(['assessment_id', 'version']);
            });

            Schema::table('rubrics', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assessment_id');
            });

            // Versions are per assessment now, so more than one assessment may have repeated them; renumbering is
            // not attempted here. Restore the backup instead if you need the old shape back.
        }

        Schema::dropIfExists('assessments');
    }
};
