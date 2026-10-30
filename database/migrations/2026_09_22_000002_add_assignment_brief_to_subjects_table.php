<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The assignment itself, which the subject had no room for.
     *
     * A subject carried a code, a name and a group size — enough to organise
     * people into groups, but nothing that said what the groups were meant to
     * produce or by when. Students were told that out of band, so the system
     * could show a task board full of deadlines while staying silent about the
     * only date that decided the mark.
     *
     * Both nullable: a subject exists before its assignment is written, and
     * requiring a brief up front would block creating the groups.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->text('brief')->nullable()->after('name');
            $table->date('project_deadline')->nullable()->after('brief');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['brief', 'project_deadline']);
        });
    }
};
