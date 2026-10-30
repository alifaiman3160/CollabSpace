<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RubricCriterion extends Model
{
    public $timestamps = false;

    protected $fillable = ['rubric_section_id', 'position', 'name', 'weight', 'mode', 'evidence_type'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(RubricSection::class, 'rubric_section_id');
    }

    public function levels(): HasMany
    {
        return $this->hasMany(RubricLevel::class)->orderBy('position');
    }

    /** What recorded activity this criterion draws on; null and unknown values read as none. */
    public function evidence(): string
    {
        return \App\Support\Marking\EvidenceType::normalise($this->evidence_type);
    }

    public function isSuggested(): bool
    {
        return $this->mode === RubricSection::SUGGESTED;
    }
}
