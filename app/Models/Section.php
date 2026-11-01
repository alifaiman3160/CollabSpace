<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One section of a subject (1A, 1B ...). Students are enrolled in a subject with one section by the administrator;
 * a group belongs to one section and holds only students of it.
 */
class Section extends Model
{
    protected $fillable = ['subject_id', 'name'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrolments');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
