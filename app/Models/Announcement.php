<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * News from a lecturer to the students of one subject. Plain text: it is stored
 * as typed and printed with normal escaping, keeping the lecturer's line breaks.
 */
class Announcement extends Model
{
    public const TYPE = 'announcement';

    protected $fillable = ['subject_id', 'author_id', 'title', 'body'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Whether the message is long enough to need a "Read more" toggle. */
    public function isLong(): bool
    {
        return mb_strlen($this->body) > 200 || substr_count($this->body, "\n") >= 3;
    }
}
