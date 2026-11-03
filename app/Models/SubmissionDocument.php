<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One document that went into a submission's PDF (a snapshot of its title; the PDF itself is the record). */
class SubmissionDocument extends Model
{
    public $timestamps = false;

    protected $fillable = ['submission_id', 'document_id', 'title', 'position'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }
}
