<?php

namespace App\Models;

use App\Support\SubjectFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file the lecturer attached to a subject. The stored name is random; the original name is only for display. */
class SubjectFile extends Model
{
    public const KINDS = [
        'question' => 'Question',
        'marking_guide' => 'Marking guide',
        'notes' => 'Notes',
        'slides' => 'Slides',
        'other' => 'Other',
    ];

    protected $fillable = ['subject_id', 'assessment_id', 'uploaded_by', 'kind', 'title', 'original_name', 'stored_path', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** The assessment it is for, or none when it is for the whole subject. */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * For a shared file (no assessment of its own): the assessments it is shown under. Empty means every assessment.
     */
    public function targets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'subject_file_assessment');
    }

    /** Whether a student reading this assessment's page should see the file. With no assessment (a subject with none) all show. */
    public function reaches(?Assessment $assessment): bool
    {
        if ($this->assessment_id !== null) {
            return $assessment === null || $this->assessment_id === $assessment->getKey();
        }

        if ($assessment === null || $this->targets->isEmpty()) {
            return true;
        }

        return $this->targets->contains('id', $assessment->getKey());
    }

    /** For a shared file: "All assessments", or the titles it is shown under. */
    public function audienceLabel(): string
    {
        return $this->targets->isEmpty() ? 'All assessments' : $this->targets->sortBy('position')->pluck('title')->implode(', ');
    }

    /** "Assignment 1", or "Course materials". */
    public function assessmentLabel(): string
    {
        return $this->assessment_id === null ? 'Course materials' : ($this->relationLoaded('assessment') ? ($this->assessment?->title ?? 'Course materials') : $this->assessment()->value('title') ?? 'Course materials');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Other';
    }

    public function sizeLabel(): string
    {
        // Plain arithmetic, so it needs no PHP extension (Number::fileSize wants intl, which a XAMPP install may not have).
        if ($this->size < 1024) {
            return $this->size.' B';
        }

        if ($this->size < 1024 * 1024) {
            return number_format($this->size / 1024, 1).' KB';
        }

        return number_format($this->size / (1024 * 1024), 1).' MB';
    }

    /** The name the browser saves it under: the original, with anything unsafe taken out. */
    public function downloadName(): string
    {
        return SubjectFiles::safeName($this->original_name, pathinfo($this->stored_path, PATHINFO_EXTENSION));
    }

    /** A PDF opens in the browser; everything else downloads. */
    public function opensInBrowser(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
