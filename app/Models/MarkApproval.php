<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lecturer's sign-off of one student's marks, with the sheet frozen as it was.
 * Active until revoked; a revoked approval is kept, with who revoked it and when.
 */
class MarkApproval extends Model
{
    protected $fillable = [
        'rubric_id', 'group_id', 'student_id', 'approved_by', 'approved_at',
        'snapshot', 'audit', 'total', 'revoked_by', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'audit' => 'array',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'total' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }
}
