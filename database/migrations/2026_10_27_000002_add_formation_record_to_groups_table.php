<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How each group came to exist.
     *
     * A lecturer looking at a group three weeks into the semester has no way
     * to tell whether they built it by hand, whether the system dealt it out
     * to balance ability, or whether it was imported from students' own
     * choices. That matters when a group is struggling: a self-chosen group
     * and an assigned one fail for different reasons.
     *
     * formation_method is one of manual, balanced, imported. Every existing row
     * becomes manual, which is true — before this, a group could only be
     * created by hand.
     *
     * formation_meta carries what was true at the moment of formation, and is
     * deliberately a snapshot. A balanced group records the average CGPA it was
     * dealt with, because CGPAs are edited later and the question "was this
     * group balanced when it was made" must keep the answer it had.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('formation_method', 20)->default('manual')->after('group_size');
            $table->json('formation_meta')->nullable()->after('formation_method');
            $table->foreignId('formed_by')->nullable()->after('formation_meta')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('formed_at')->nullable()->after('formed_by');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('formed_by');
            $table->dropColumn(['formation_method', 'formation_meta', 'formed_at']);
        });
    }
};
