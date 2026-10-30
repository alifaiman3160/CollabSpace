<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notifications. Named AppNotification so it does not collide with
 * Illuminate\Notifications\Notification.
 */
class AppNotification extends Model
{
    use HasFactory;

    protected $table = 'app_notifications';

    /** The table carries created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'reference_id',
        'message',
        'link',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * Narrows to the notifications already raised about one record.
     *
     * reference_id is meaningless without its type — a task id and a document
     * id are both just integers — so the two always travel together.
     */
    public function scopeAbout(Builder $query, string $type, int $referenceId): Builder
    {
        return $query->where('type', $type)->where('reference_id', $referenceId);
    }
}
