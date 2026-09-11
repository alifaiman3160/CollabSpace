<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'user_id',
        'body',
        'file_path',
        'file_name',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
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

    public function hasAttachment(): bool
    {
        return $this->file_path !== null;
    }

    /**
     * True when the attachment is an image the thread can render inline.
     *
     * Read from the stored path, which this application generated, rather than
     * the display name, which the uploader chose.
     */
    public function isImage(): bool
    {
        return in_array(
            strtolower(pathinfo((string) $this->file_path, PATHINFO_EXTENSION)),
            ['png', 'jpg', 'jpeg'],
            true,
        );
    }

    /**
     * The random component of the stored filename, used as an unguessable part
     * of the download URL.
     *
     * Membership is what actually authorises a download; this only stops the
     * URL being enumerable from a message id alone. Seeded rows predate the
     * random naming, so they fall back to the whole basename.
     */
    public function fileToken(): ?string
    {
        if ($this->file_path === null) {
            return null;
        }

        return pathinfo($this->file_path, PATHINFO_FILENAME);
    }

    public function scopeWithAttachment(Builder $query): Builder
    {
        return $query->whereNotNull('file_path');
    }
}
