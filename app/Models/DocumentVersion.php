<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasFactory;

    /** The table carries created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'edited_by',
        'version_no',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
