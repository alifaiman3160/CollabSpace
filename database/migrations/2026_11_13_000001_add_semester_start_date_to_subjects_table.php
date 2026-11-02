<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The first day of Week 1 of a subject's semester, filled in by the lecturer. Display only: it drives the "Week 5" chip
 * on the subject cards and nothing else. Empty means no week is shown, so there is nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subjects', 'semester_start_date')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->date('semester_start_date')->nullable()->after('project_deadline');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subjects', 'semester_start_date')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->dropColumn('semester_start_date');
            });
        }
    }
};
