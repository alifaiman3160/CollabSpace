<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One version of an assessment's marking rubric. Immutable once saved: a change is a
 * new version, so earlier versions, and any marks approved against them, are
 * never altered.
 */
class Rubric extends Model
{
    protected $fillable = ['subject_id', 'assessment_id', 'name', 'version', 'is_current', 'source_file', 'created_by'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        // A rubric always belongs to an assessment. Code that does not say which gets the subject's default one.
        static::creating(function (Rubric $rubric) {
            if ($rubric->assessment_id === null && $rubric->subject_id !== null) {
                $rubric->assessment_id = Assessment::defaultFor(Subject::findOrFail($rubric->subject_id))->getKey();
            }
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(RubricSection::class)->orderBy('position');
    }

    public function criteria(): HasManyThrough
    {
        return $this->hasManyThrough(RubricCriterion::class, RubricSection::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MarkApproval::class);
    }

    /**
     * True once any student's marks against this version are approved, and until
     * every such approval is revoked.
     *
     * A locked version cannot have its evidence types or criterion modes changed,
     * and cannot be deleted: approved marks rest on it as it is. A change is a new
     * version.
     */
    public function isLocked(): bool
    {
        return $this->approvals()->whereNull('revoked_at')->exists();
    }
}
