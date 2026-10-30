<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the lecturer decides, as opposed to what the system suggests.
 *
 *   mark_choices           the level the lecturer chose for one student on one
 *                          criterion, with the suggestions as they stood then
 *   mark_section_methods   how a section that mixes System-suggested and Manual
 *                          criteria is totalled, per group
 *   mark_approvals         the lecturer's sign-off for one student, with every
 *                          figure it rests on frozen at that moment
 *
 * Nothing here is a final mark until an approval row exists and has not been
 * revoked. An approval keeps its own copy of the levels, marks, facts and
 * provider record, so later activity or a re-run of the suggestions cannot move
 * a mark that was signed off. Revoking keeps the row, with who and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mark_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rubric_criterion_id')->constrained('rubric_criteria')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('rubric_level_id')->constrained('rubric_levels')->cascadeOnDelete();

            // What was on offer when the choice was made, kept so the choice can be explained later.
            $table->foreignId('baseline_level_id')->nullable()->constrained('rubric_levels')->nullOnDelete();
            $table->foreignId('ai_level_id')->nullable()->constrained('rubric_levels')->nullOnDelete();

            // rule_based | ai | lecturer. "lecturer" is a choice with nothing suggested, or one that overrides it.
            $table->string('source', 12);
            $table->text('override_reason')->nullable();

            $table->foreignId('chosen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['rubric_id', 'group_id', 'student_id', 'rubric_criterion_id'], 'mark_choice_unique');
        });

        Schema::create('mark_section_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rubric_section_id')->constrained('rubric_sections')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('method', 8);            // add | scale
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['rubric_id', 'rubric_section_id', 'group_id'], 'mark_method_unique');
        });

        Schema::create('mark_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');

            // The sheet exactly as approved: per section and criterion, the level, its marks,
            // the baseline and AI levels, the reason, the facts, and how each total was reached.
            $table->json('snapshot');
            // Provider, model, prompt and the provider's raw reply, as they were.
            $table->json('audit')->nullable();

            $table->decimal('total', 8, 2);

            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['rubric_id', 'group_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_approvals');
        Schema::dropIfExists('mark_section_methods');
        Schema::dropIfExists('mark_choices');
    }
};
