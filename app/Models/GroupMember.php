<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * group_members carries its own id and joined_at, so it is a Pivot model
 * rather than a bare pivot table.
 */
class GroupMember extends Pivot
{
    protected $table = 'group_members';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'user_id',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
