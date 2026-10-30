<?php

namespace App\Models;

use App\Support\Rubric\RubricScoring;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RubricSection extends Model
{
    public const SUGGESTED = 'suggested';

    public const MANUAL = 'manual';

    public $timestamps = false;

    protected $fillable = ['rubric_id', 'position', 'title', 'mode', 'declared_total', 'scoring'];

    protected function casts(): array
    {
        return ['declared_total' => 'decimal:2'];
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('position');
    }

    public function isSuggested(): bool
    {
        return $this->mode === self::SUGGESTED;
    }

    /** The most a student could earn under each formula, from the saved criteria. */
    public function maxima(): array
    {
        $this->loadMissing('criteria.levels');

        return RubricScoring::maxima($this->criteria->map(fn ($c) => [
            'weight' => $c->weight,
            'levels' => $c->levels->map(fn ($l) => ['marks' => $l->marks])->all(),
        ])->all());
    }

    /** The maximum under the formula this section uses, or null if none is chosen. */
    public function maximumMarks(): ?float
    {
        return RubricScoring::maximum($this->scoring, $this->maxima());
    }

    /** "marks = level marks × weight; maximum 30; declared total 30". */
    public function scoringExplained(): string
    {
        return RubricScoring::describe(
            $this->scoring,
            $this->declared_total === null ? null : (float) $this->declared_total,
            $this->maxima(),
        );
    }

    /**
     * Marks for one criterion of this section at one level. The only place a
     * saved rubric turns a level into a number.
     */
    public function marksFor(RubricCriterion $criterion, RubricLevel $level): float
    {
        return RubricScoring::marks(
            $this->scoring ?? throw new \LogicException('This section has no scoring formula chosen.'),
            $criterion->weight,
            $level->marks,
        );
    }
}
