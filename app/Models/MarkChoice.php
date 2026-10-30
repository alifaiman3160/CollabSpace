<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The level the lecturer chose for a student on a criterion. A choice is a
 * decision, not a mark: it becomes part of a final mark only through an approval.
 */
class MarkChoice extends Model
{
    public const RULE_BASED = 'rule_based';

    public const AI = 'ai';

    public const LECTURER = 'lecturer';

    protected $fillable = [
        'rubric_id', 'rubric_criterion_id', 'group_id', 'student_id', 'rubric_level_id',
        'baseline_level_id', 'ai_level_id', 'source', 'override_reason', 'chosen_by',
    ];

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(RubricLevel::class, 'rubric_level_id');
    }
}
