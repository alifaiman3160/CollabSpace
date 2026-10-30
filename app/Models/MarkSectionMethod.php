<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How a section mixing System-suggested and Manual criteria is totalled for a
 * group: "add" counts every criterion; "scale" scores the System-suggested ones
 * and scales that to the section's full marks.
 */
class MarkSectionMethod extends Model
{
    public const ADD = 'add';

    public const SCALE = 'scale';

    protected $fillable = ['rubric_id', 'rubric_section_id', 'group_id', 'method', 'set_by'];
}
