<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RubricLevel extends Model
{
    public $timestamps = false;

    protected $fillable = ['rubric_criterion_id', 'position', 'label', 'marks', 'marks_range', 'descriptor'];

    protected function casts(): array
    {
        return ['marks' => 'decimal:2'];
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }

    /**
     * How a level reads on a screen: "Developing, 1 mark", "Good, 1.5 marks",
     * "Excellent, 4–5 marks". The label first and the marks spelt out, rather than
     * a bare number in brackets that could be a weight, a count or a position.
     */
    public function display(): string
    {
        return self::describe($this->label, $this->marks, $this->marks_range);
    }

    /** The same text from plain values, for a frozen snapshot. */
    public static function describe(string $label, float|string $marks, ?string $range = null): string
    {
        return "{$label}, ".self::marksText($marks, $range);
    }

    /** "1 mark", "1.5 marks", "4–5 marks": the marks alone, for a small caption. */
    public static function marksText(float|string $marks, ?string $range = null): string
    {
        $figure = filled($range) ? $range : rtrim(rtrim(number_format((float) $marks, 2, '.', ''), '0'), '.');

        // One mark is singular; so is nothing else, and a range is always plural.
        $single = blank($range) && (float) $marks === 1.0;

        return "{$figure} ".($single ? 'mark' : 'marks');
    }

    /** "4–5" when the file gave a range, otherwise the marks. */
    public function marksLabel(): string
    {
        return $this->marks_range ?: rtrim(rtrim((string) $this->marks, '0'), '.');
    }
}
