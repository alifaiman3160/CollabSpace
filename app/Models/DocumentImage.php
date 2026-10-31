<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture placed in a document. The file lives on the private disk and is only
 * reachable through the route that checks the viewer belongs to the document's group.
 */
class DocumentImage extends Model
{
    protected $fillable = ['document_id', 'path', 'mime', 'size', 'original_name'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** The address written into a document's body: role-neutral, so a lecturer's read-only view works too. */
    public function url(): string
    {
        return route('documents.images.show', $this, absolute: false);
    }
}
