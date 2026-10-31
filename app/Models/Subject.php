<?php

namespace App\Models;

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
        'group_size',
    ];

    protected function casts(): array
    {
        return [
            'group_size' => 'integer',
            'project_deadline' => 'date',
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
        return filled($this->brief) || $this->project_deadline !== null;
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
        if ($this->project_deadline === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->project_deadline->startOfDay(), false);
    }

    public function projectDeadlinePassed(): bool
    {
        $days = $this->daysToProjectDeadline();

        return $days !== null && $days < 0;
    }

    /** How the countdown reads on screen. */
    public function projectDeadlineLabel(): ?string
    {
        $days = $this->daysToProjectDeadline();

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

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
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
