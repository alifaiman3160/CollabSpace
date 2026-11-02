<?php

use App\Support\Assessments\Backfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The brief and the files per assessment. An assessment gets its own brief text, and a subject file may belong to one
 * assessment (null means the whole subject).
 *
 * Additive, and the backfill runs once, with the columns: each subject's existing brief is copied into its first
 * assessment, and its existing files are assigned to that assessment, so what students read today is what they read
 * after. subjects.brief is kept (it stays the fallback while every assessment brief is empty). Take a backup first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fresh = false;

        if (! Schema::hasColumn('assessments', 'brief')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->text('brief')->nullable()->after('title');
            });

            $fresh = true;
        }

        if (! Schema::hasColumn('subject_files', 'assessment_id')) {
            Schema::table('subject_files', function (Blueprint $table) {
                $table->foreignId('assessment_id')->nullable()->after('subject_id')->constrained('assessments')->nullOnDelete();
            });

            $fresh = true;
        }

        if ($fresh) {
            Backfill::briefsAndFiles();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subject_files', 'assessment_id')) {
            Schema::table('subject_files', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assessment_id');
            });
        }

        if (Schema::hasColumn('assessments', 'brief')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->dropColumn('brief');
            });
        }
    }
};