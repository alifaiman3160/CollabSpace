<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per scored action. `weight` is denormalised from
 * config('contribution.weights') at write time so historical scores stay
 * stable if the weights are later retuned.
 */
class ActivityLog extends Model
{
    use HasFactory;

    /** The table carries created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'group_id',
        'activity_type',
        'reference_id',
        'weight',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'reference_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The configured weight for an activity type, defaulting to 1. */
    public static function weightFor(string $activityType): int
    {
        return (int) config("contribution.weights.{$activityType}", 1);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('activity_type', $type);
    }

    public function scopeForGroup(Builder $query, int $groupId): Builder
    {
        return $query->where('group_id', $groupId);
    }
}
