<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One student has opened one announcement. Written once; never updated. */
class AnnouncementRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['announcement_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
