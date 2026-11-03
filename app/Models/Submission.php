<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A group's submission for an assessment: one frozen PDF. The current one of a group for an assessment is the latest,
 * unless that was withdrawn; every earlier or withdrawn one stays as history, with its file.
 */
class Submission extends Model
{
    protected $fillable = ['group_id', 'assessment_id', 'submitted_by', 'submitted_at', 'is_late', 'withdrawn_at', 'withdrawn_by', 'pdf_path', 'pdf_size', 'page_count', 'note'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'is_late' => 'boolean',
            'pdf_size' => 'integer',
            'page_count' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SubmissionDocument::class)->orderBy('position');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at');
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /** "On time" or "Late". */
    public function timingLabel(): string
    {
        return $this->is_late ? 'Late' : 'On time';
    }

    /** The file name the browser saves it under: "SECJ3104-Group-A-Assignment-1-2026-11-03.pdf". */
    public function downloadName(): string
    {
        $this->loadMissing('group.subject', 'assessment');

        $parts = [$this->group->subject->code, $this->group->name, $this->assessment->title, $this->submitted_at->format('Y-m-d')];
        $name = collect($parts)->map(fn ($p) => trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii((string) $p)), '-'))->filter()->implode('-');

        return ($name !== '' ? $name : 'Submission').'.pdf';
    }

    /** The latest submission of a group for an assessment, whatever its state, or null. */
    public static function latestFor(int $groupId, int $assessmentId): ?self
    {
        return static::query()->where('group_id', $groupId)->where('assessment_id', $assessmentId)
            ->orderByDesc('submitted_at')->orderByDesc('id')->first();
    }

    /**
     * The current submission of a group for an assessment: the latest one, unless that was withdrawn (then none stands;
     * withdrawing does not bring back an older, replaced one). Null when nothing stands.
     */
    public static function currentFor(int $groupId, int $assessmentId): ?self
    {
        $latest = static::latestFor($groupId, $assessmentId);

        return $latest !== null && ! $latest->isWithdrawn() ? $latest : null;
    }

    /**
     * The latest submission of each (group, assessment) pair, keyed "groupId-assessmentId", from one query, so a list
     * page asks once for all the groups it shows.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $assessmentIds
     * @return Collection<string, self>
     */
    public static function latestMap(array $groupIds, array $assessmentIds): Collection
    {
        if ($groupIds === [] || $assessmentIds === []) {
            return collect();
        }

        return static::query()->whereIn('group_id', $groupIds)->whereIn('assessment_id', $assessmentIds)
            ->orderByDesc('submitted_at')->orderByDesc('id')->get()
            ->unique(fn (self $s) => $s->group_id.'-'.$s->assessment_id)
            ->keyBy(fn (self $s) => $s->group_id.'-'.$s->assessment_id);
    }

    /** "submitted", "late", "withdrawn" or "none", for a latest submission (or null). */
    public static function statusOf(?self $latest): string
    {
        return match (true) {
            $latest === null => 'none',
            $latest->isWithdrawn() => 'withdrawn',
            $latest->is_late => 'late',
            default => 'submitted',
        };
    }
}
