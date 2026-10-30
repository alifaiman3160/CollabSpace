<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence-based mark suggestions: what each criterion draws on, and what was
 * suggested for each student.
 *
 *   rubric_criteria.evidence_type  what recorded activity the criterion looks at
 *   marking_runs                   one request to the AI provider, kept for audit
 *   mark_suggestions               one suggestion per student per criterion
 *
 * A suggestion is never a mark. It carries the rule-based baseline, the AI's
 * choice when there was one, and a snapshot of the facts both were based on, so
 * either can be explained later whatever has happened to the activity since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubric_criteria', function (Blueprint $table) {
            // task_completion | reliability | communication | collaboration | none.
            // Null on a system-suggested criterion is read as "none".
            $table->string('evidence_type', 20)->nullable()->after('mode');
        });

        Schema::create('marking_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 12);                    // ok | disabled | limited | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('message')->nullable();             // why, when it was not ok
            $table->longText('prompt')->nullable();          // system instructions and the anonymised payload
            $table->longText('response')->nullable();        // the provider's reply, as received
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['rubric_id', 'group_id']);
        });

        Schema::create('mark_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_criterion_id')->constrained('rubric_criteria')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('marking_run_id')->nullable()->constrained('marking_runs')->nullOnDelete();

            $table->string('evidence_type', 20);
            $table->boolean('needs_input')->default(false);  // no evidence, or none recorded
            $table->string('needs_input_reason')->nullable();

            $table->foreignId('baseline_level_id')->nullable()->constrained('rubric_levels')->cascadeOnDelete();
            $table->text('baseline_note')->nullable();

            // not_asked | ok | disabled | limited | failed | invalid
            $table->string('ai_status', 12)->default('not_asked');
            $table->foreignId('ai_level_id')->nullable()->constrained('rubric_levels')->cascadeOnDelete();
            $table->text('ai_rationale')->nullable();
            $table->text('ai_message')->nullable();          // why there is no AI suggestion

            $table->boolean('differs')->default(false);      // baseline and AI more than one level apart
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->json('facts');                           // snapshot of what the suggestion was based on
            $table->timestamps();

            $table->unique(['group_id', 'student_id', 'rubric_criterion_id'], 'mark_suggestion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_suggestions');
        Schema::dropIfExists('marking_runs');

        Schema::table('rubric_criteria', function (Blueprint $table) {
            $table->dropColumn('evidence_type');
        });
    }
};
