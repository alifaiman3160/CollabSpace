<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'lecturer_id',
        'code',
        'name',
        'brief',
        'project_deadline',
        'semester_start_date',
        'group_size',
    ];

    protected function casts(): array
    {
        return [
            'group_size' => 'integer',
            'project_deadline' => 'date',
            'semester_start_date' => 'date',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The assignment
    |--------------------------------------------------------------------------
    |
    | A subject may carry a brief, a project deadline, both or neither. The
    | arithmetic below lives here rather than in the views because three places
    | need it — the dashboard, the workspace and the reminder command — and
    | three copies of a date calculation is three chances to disagree about
    | whether today counts.
    |
    */

    /** True when there is an assignment to show at all. */
    public function hasAssignment(): bool
    {
        return filled($this->briefOf($this->activeAssessment())) || $this->projectDeadline() !== null;
    }

    /**
     * The brief a student reads for an assessment: its own text. The subject's old single brief is only the fallback
     * while every assessment's brief is empty (a subject that has no assessment just uses it).
     */
    public function briefOf(?Assessment $assessment): ?string
    {
        $this->loadMissing('assessments');

        if ($assessment !== null && filled($assessment->brief)) {
            return $assessment->brief;
        }

        $everyBriefEmpty = $this->assessments->every(fn (Assessment $a) => blank($a->brief));

        return $everyBriefEmpty && filled($this->brief) ? $this->brief : null;
    }

    /**
     * Whole days from today to the project deadline.
     *
     * Zero means today, negative means it has passed. Both sides are midnight,
     * so a deadline is a whole day away or it is not — nobody's project is due
     * in 2.4 days.
     */
    public function daysToProjectDeadline(): ?int
    {
        return self::daysUntil($this->projectDeadline());
    }

    /** Whole days from today to any date (the same rule), or null for none. */
    public static function daysUntil(?CarbonInterface $date): ?int
    {
        return $date === null ? null : (int) now()->startOfDay()->diffInDays(Carbon::instance($date)->startOfDay(), false);
    }

    public function projectDeadlinePassed(): bool
    {
        $days = $this->daysToProjectDeadline();

        return $days !== null && $days < 0;
    }

    /** How the countdown reads on screen. */
    public function projectDeadlineLabel(): ?string
    {
        return self::countdownLabel($this->daysToProjectDeadline());
    }

    /** "12 days left", "Due tomorrow", "Overdue by 2 days" for a number of days. */
    public static function countdownLabel(?int $days): ?string
    {
        if ($days === null) {
            return null;
        }

        return match (true) {
            $days === 0 => 'Due today',
            $days === 1 => 'Due tomorrow',
            $days > 1 => $days.' days left',
            $days === -1 => 'Overdue by a day',
            default => 'Overdue by '.abs($days).' days',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | The teaching week
    |--------------------------------------------------------------------------
    |
    | Display only. Week 1 is the week that contains the semester start date, counted in whole calendar days in the
    | application's timezone, with no breaks skipped: Week n runs from start + 7(n-1) days for seven days.
    |
    */

    /** The semester is shown for this many weeks; after that there is nothing to say. */
    public const SEMESTER_WEEKS = 20;

    /** The week number of today (or of $now), or null: no start date, not begun yet, or past the last week. */
    public function currentWeek(?CarbonInterface $now = null): ?int
    {
        $week = $this->weekOf($now ?? now());

        return $week !== null && $week <= self::SEMESTER_WEEKS ? $week : null;
    }

    /** The week number of any date by the same rule (no upper limit), or null with no start date or before it. */
    public function weekOf(CarbonInterface $date): ?int
    {
        if ($this->semester_start_date === null) {
            return null;
        }

        $zone = config('app.timezone');

        // Calendar days in the application's timezone, so 23:59 and 00:01 are the days they look like.
        $start = Carbon::parse($this->semester_start_date->format('Y-m-d'), $zone)->startOfDay();
        $day = Carbon::instance($date)->setTimezone($zone)->startOfDay();

        if ($day->lt($start)) {
            return null;
        }

        return intdiv((int) round($start->diffInDays($day)), 7) + 1;
    }

    /** The assessments of this subject, in the order the lecturer set. */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The assessment that is on now: the one whose dates contain today; otherwise the next one coming up; otherwise the
     * most recent one that has passed; null when the subject has no assessment. Read from the loaded list, so a page
     * that shows many subjects loads them once.
     */
    public function activeAssessment(?CarbonInterface $now = null): ?Assessment
    {
        $this->loadMissing('assessments');

        return Assessment::pickActive($this->assessments, $now);
    }

    /**
     * The date the project is due: the active assessment's, and the subject's own project deadline when there is none
     * (a subject that has no assessment yet, or whose assessment has no due date).
     */
    /** The due date of one assessment: its own, else (for the default one) the subject's own project deadline. */
    public function dueOf(?Assessment $assessment): ?Carbon
    {
        return $assessment?->due_on ?? $this->project_deadline;
    }

    public function projectDeadline(): ?Carbon
    {
        return $this->activeAssessment()?->due_on ?? $this->project_deadline;
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /** The project question, marking guide and other files the lecturer attached. */
    public function files(): HasMany
    {
        return $this->hasMany(SubjectFile::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    public function rubrics(): HasMany
    {
        return $this->hasMany(Rubric::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    /** Every task under every group of this subject. */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Group::class);
    }

    /** Every document under every group of this subject. */
    public function documents(): HasManyThrough
    {
        return $this->hasManyThrough(Document::class, Group::class);
    }

    /**
     * Every membership row across this subject's groups. Counting this is how
     * "how many students are enrolled in this subject" is answered, since a
     * student joins a subject by being put in one of its groups.
     */
    public function groupMembers(): HasManyThrough
    {
        return $this->hasManyThrough(GroupMember::class, Group::class);
    }
}
