<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to the AI provider about one student, kept for audit: which
 * provider and model, the instructions and anonymised data sent, and what came
 * back. Contains no names, emails, matric numbers or CGPA, and no API key.
 */
class MarkingRun extends Model
{
    protected $fillable = [
        'rubric_id', 'group_id', 'student_id', 'provider', 'model', 'status',
        'attempts', 'message', 'prompt', 'response', 'latency_ms',
    ];

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
