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
        'group_size',
    ];

    protected function casts(): array
    {
        return [
            'group_size' => 'integer',
        ];
    }

    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
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
