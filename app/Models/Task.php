<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    public const STATUS_TODO = 'todo';

    public const STATUS_INPROGRESS = 'inprogress';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'group_id',
        'assigned_to',
        'created_by',
        'updated_by',
        'title',
        'description',
        'status',
        'deadline',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /** Past its deadline and still not finished. */
    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && ! $this->isDone()
            && $this->deadline->isPast();
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('deadline')
            ->where('status', '!=', self::STATUS_DONE)
            ->whereDate('deadline', '<', now()->toDateString());
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
