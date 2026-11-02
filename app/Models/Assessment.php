<?php

namespace App\Models;

use App\Support\Assessments\Backfill;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One assessment of a subject (Assignment 1, Project ...): its own dates and its own marking rubric. The groups are the
 * subject's, the same for every assessment.
 *
 * The window runs from starts_on 00:00 to due_on 23:59:59 in the application's timezone (Asia/Kuala_Lumpur). An empty
 * start is the beginning of time, an empty due date is open-ended. It is read-side only: activity outside every
 * window counts toward no assessment, and nothing in the log is ever changed. The weight is informational and is never
 * used in a calculation.
 *
 * The window is only applied when the subject has two or more assessments: with one, everything counts as it always
 * did, whatever its dates say.
 */
class Assessment extends Model
{
    public const TYPES = ['assignment' => 'Assignment', 'project' => 'Project'];

    public const MAX_PER_SUBJECT = 6;

    protected $fillable = ['subject_id', 'title', 'brief', 'type', 'weight', 'starts_on', 'due_on', 'position'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on' => 'date',
            'weight' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function rubrics(): HasMany
    {
        return $this->hasMany(Rubric::class);
    }

    /** Files meant for this assessment (the whole-subject ones have no assessment and belong to every one). */
    public function files(): HasMany
    {
        return $this->hasMany(SubjectFile::class);
    }

    /**
     * The active one of a subject's assessments: the one whose dates contain today; otherwise the next one coming up;
     * otherwise the most recent one that has passed; the first when none has any date; null with none at all.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $all  already in position order
     */
    public static function pickActive(\Illuminate\Support\Collection $all, ?CarbonInterface $now = null): ?self
    {
        if ($all->isEmpty()) {
            return null;
        }

        $today = Carbon::instance($now ?? now())->setTimezone(config('app.timezone'));

        $current = $all->first(fn (self $a) => $a->contains($today->copy()->startOfDay()) || $a->contains($today->copy()->endOfDay()));

        if ($current !== null) {
            return $current;
        }

        $upcoming = $all->filter(fn (self $a) => $a->windowStart() !== null && $a->windowStart()->gt($today))
            ->sortBy(fn (self $a) => $a->windowStart()->timestamp)->first();

        if ($upcoming !== null) {
            return $upcoming;
        }

        return $all->filter(fn (self $a) => $a->windowEnd() !== null && $a->windowEnd()->lt($today))
            ->sortByDesc(fn (self $a) => $a->windowEnd()->timestamp)->first() ?? $all->first();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? 'Project';
    }

    /** The first moment of the window, or null for the beginning of time. */
    public function windowStart(): ?Carbon
    {
        return $this->starts_on === null ? null : Carbon::parse($this->starts_on->format('Y-m-d'), config('app.timezone'))->startOfDay();
    }

    /** The last moment of the window, or null for open-ended. */
    public function windowEnd(): ?Carbon
    {
        return $this->due_on === null ? null : Carbon::parse($this->due_on->format('Y-m-d'), config('app.timezone'))->endOfDay();
    }

    /** Whether a moment falls inside this assessment's own dates. */
    public function contains(Carbon $moment): bool
    {
        return ($this->windowStart() === null || $moment->gte($this->windowStart()))
            && ($this->windowEnd() === null || $moment->lte($this->windowEnd()));
    }

    /**
     * What activity is counted for this assessment: [from, to] (either may be null), or null when everything counts
     * (the subject has only this assessment, or this one has no dates at all).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}|null
     */
    public function activityWindow(): ?array
    {
        $subject = $this->subject;

        if ($subject === null || $subject->assessments->count() < 2) {
            return null;
        }

        $from = $this->windowStart();
        $to = $this->windowEnd();

        return $from === null && $to === null ? null : [$from, $to];
    }

    /** Limits a query on a timestamp column to this assessment's window. Unchanged when everything counts. */
    public static function limit($query, ?self $assessment, string $column = 'created_at')
    {
        $window = $assessment?->activityWindow();

        if ($window === null) {
            return $query;
        }

        return $query
            ->when($window[0], fn ($q, $from) => $q->where($column, '>=', $from))
            ->when($window[1], fn ($q, $to) => $q->where($column, '<=', $to));
    }

    /** The subject's default assessment ("Project"), made when it has none yet. */
    public static function defaultFor(Subject $subject): self
    {
        $existing = $subject->assessments()->first();

        if ($existing !== null) {
            return $existing;
        }

        $made = static::create([
            'subject_id' => $subject->getKey(),
            'title' => Backfill::DEFAULT_TITLE,
            'type' => 'project',
            'due_on' => $subject->project_deadline,
            'position' => 1,
        ]);

        $subject->unsetRelation('assessments');

        return $made;
    }

    public function hasApprovals(): bool
    {
        return MarkApproval::query()->whereIn('rubric_id', $this->rubrics()->select('id'))->exists();
    }

    /** Why this cannot be deleted (approved marks, marks or suggestions exist), or null when it can. */
    public function deleteBlock(): ?string
    {
        $rubrics = $this->rubrics()->select('id');

        if ($this->hasApprovals()) {
            return 'it has approved marks';
        }

        if (MarkChoice::query()->whereIn('rubric_id', $rubrics)->exists()) {
            return 'it has marks';
        }

        if (MarkSuggestion::query()->whereIn('rubric_criterion_id', RubricCriterion::query()
            ->whereIn('rubric_section_id', RubricSection::query()->whereIn('rubric_id', $rubrics)->select('id'))->select('id'))->exists()) {
            return 'it has suggestions';
        }

        return null;
    }
}
