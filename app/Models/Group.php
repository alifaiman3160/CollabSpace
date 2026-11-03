<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory;

    public const FORMED_MANUAL = 'manual';

    public const FORMED_BALANCED = 'balanced';

    public const FORMED_IMPORTED = 'imported';

    protected $fillable = [
        'subject_id',
        'section_id',
        'name',
        'group_size',
        'formation_method',
        'formation_meta',
        'formed_by',
        'formed_at',
    ];

    protected function casts(): array
    {
        return [
            'group_size' => 'integer',
            'formation_meta' => 'array',
            'formed_at' => 'datetime',
        ];
    }

    /** The lecturer who formed it, when that account still exists. */
    public function formedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'formed_by');
    }

    /**
     * The other groups made in the same batch: the same click of Confirm.
     *
     * Lets a lecturer looking at one balanced group see the rest of the set it
     * was dealt with, which is what "balanced" means.
     *
     * @return \Illuminate\Support\Collection<int, Group>
     */
    public function formationPeers(): \Illuminate\Support\Collection
    {
        $batch = $this->formation_meta['batch'] ?? null;

        if ($batch === null) {
            return collect();
        }

        return static::query()
            ->where('subject_id', $this->subject_id)
            ->where('formation_meta->batch', $batch)
            ->whereKeyNot($this->getKey())
            ->orderBy('name')
            ->get();
    }

    /** How the group came about, in the words a lecturer would use. */
    public function formationLabel(): string
    {
        return match ($this->formation_method) {
            self::FORMED_BALANCED => 'Balanced by CGPA',
            self::FORMED_IMPORTED => 'Imported from CSV',
            default => 'Created manually',
        };
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** The section its members are all enrolled in; empty until the first member decides it. */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** The pivot rows themselves, when joined_at matters. */
    public function groupMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->using(GroupMember::class)
            ->withPivot('joined_at')
            ->as('membership');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
