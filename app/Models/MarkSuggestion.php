<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suggested level for one student on one criterion. Never a mark: the baseline
 * and the AI's choice sit side by side, with the facts both came from, for the
 * lecturer to accept or override.
 */
class MarkSuggestion extends Model
{
    protected $fillable = [
        'rubric_criterion_id', 'group_id', 'student_id', 'marking_run_id',
        'evidence_type', 'needs_input', 'needs_input_reason', 'insufficient_data', 'confidence',
        'baseline_level_id', 'baseline_note',
        'ai_status', 'ai_level_id', 'ai_rationale', 'ai_message',
        'differs', 'provider', 'model', 'facts',
    ];

    protected function casts(): array
    {
        return ['needs_input' => 'boolean', 'insufficient_data' => 'boolean', 'differs' => 'boolean', 'facts' => 'array'];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function baselineLevel(): BelongsTo
    {
        return $this->belongsTo(RubricLevel::class, 'baseline_level_id');
    }

    public function aiLevel(): BelongsTo
    {
        return $this->belongsTo(RubricLevel::class, 'ai_level_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MarkingRun::class, 'marking_run_id');
    }

    /** The level to put forward: the AI's when it gave a valid one, else the baseline. */
    public function suggestedLevelId(): ?int
    {
        return $this->ai_level_id ?? $this->baseline_level_id;
    }

    /** "AI-suggested", "Rule-based", or null when there is nothing to suggest. */
    public function sourceLabel(): ?string
    {
        return match (true) {
            $this->ai_level_id !== null => 'AI-suggested',
            $this->baseline_level_id !== null => 'Rule-based',
            default => null,
        };
    }
}
