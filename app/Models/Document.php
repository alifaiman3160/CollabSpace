<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'created_by',
        'last_edited_by',
        'title',
        'content',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version_no', 'max');
    }
}
