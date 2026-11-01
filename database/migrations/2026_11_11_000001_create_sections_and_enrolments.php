<?php

use App\Support\Sections\Backfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sections and enrolments.
 *
 * A subject has sections (1A, 1B, ...); an administrator enrols each student in a subject with one section. Groups
 * and announcements can be tied to a section. Everything is additive: no existing column changes, and the backfill
 * (App\Support\Sections\Backfill) gives every existing subject a default section "Main", puts its groups in it and
 * enrols every student who is already in one of its groups, so what works today keeps working. Take a backup first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sections')) {
            Schema::create('sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
                $table->string('name', 20);
                $table->timestamps();

                $table->unique(['subject_id', 'name']);
            });
        }

        if (! Schema::hasTable('enrolments')) {
            Schema::create('enrolments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
                $table->foreignId('section_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'subject_id']);
                $table->index('section_id');
            });
        }

        if (! Schema::hasColumn('groups', 'section_id')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->foreignId('section_id')->nullable()->after('subject_id')->constrained('sections')->nullOnDelete();
            });
        }

        // An announcement for one section only; empty means every section.
        if (! Schema::hasColumn('announcements', 'section_id')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->foreignId('section_id')->nullable()->after('subject_id')->constrained('sections')->nullOnDelete();
            });
        }

        Backfill::run();
    }

    public function down(): void
    {
        if (Schema::hasColumn('announcements', 'section_id')) {
            Schema::table('announcements', function (Blueprint $table) {
                $table->dropConstrainedForeignId('section_id');
            });
        }

        if (Schema::hasColumn('groups', 'section_id')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropConstrainedForeignId('section_id');
            });
        }

        Schema::dropIfExists('enrolments');
        Schema::dropIfExists('sections');
    }
};
