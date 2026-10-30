<?php

namespace App\Support\Rubric;

use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\RubricLevel;
use App\Models\RubricSection;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes a reviewed rubric as the next version of a subject's rubric.
 *
 * Earlier versions are left exactly as they are, apart from no longer being the
 * current one. Nothing here edits a saved rubric: a correction is a new version,
 * so anything that was approved against version n still points at version n.
 */
class RubricStore
{
    /** @param  array<string, mixed>  $data  validated, see ConfirmRubricRequest */
    public function create(Subject $subject, User $by, array $data, ?string $sourceFile): Rubric
    {
        return DB::transaction(function () use ($subject, $by, $data, $sourceFile): Rubric {
            // Serialise per subject so two confirmations cannot take the same number.
            Subject::query()->whereKey($subject->id)->lockForUpdate()->first();

            $version = ((int) Rubric::where('subject_id', $subject->id)->max('version')) + 1;

            Rubric::where('subject_id', $subject->id)->update(['is_current' => false]);

            $rubric = Rubric::create([
                'subject_id' => $subject->id,
                'name' => $data['name'],
                'version' => $version,
                'is_current' => true,
                'source_file' => $sourceFile,
                'created_by' => $by->id,
            ]);

            foreach (array_values($data['sections']) as $s => $section) {
                $savedSection = RubricSection::create([
                    'rubric_id' => $rubric->id,
                    'position' => $s + 1,
                    'title' => $section['title'],
                    'mode' => $section['mode'],
                    'declared_total' => RubricScoring::number($section['declared_total'] ?? null),
                    'scoring' => filled($section['scoring'] ?? null) ? $section['scoring'] : null,
                ]);

                foreach (array_values($section['criteria']) as $c => $criterion) {
                    $savedCriterion = RubricCriterion::create([
                        'rubric_section_id' => $savedSection->id,
                        'position' => $c + 1,
                        'name' => $criterion['name'],
                        'weight' => $criterion['weight'] ?? null,
                        'mode' => $criterion['mode'],
                        'evidence_type' => $criterion['mode'] === RubricSection::SUGGESTED && filled($criterion['evidence_type'] ?? null)
                            ? $criterion['evidence_type']
                            : null,
                    ]);

                    foreach (array_values($criterion['levels']) as $l => $level) {
                        RubricLevel::create([
                            'rubric_criterion_id' => $savedCriterion->id,
                            'position' => $l + 1,
                            'label' => $level['label'],
                            'marks' => $level['marks'],
                            'marks_range' => filled($level['range'] ?? null) ? $level['range'] : null,
                            'descriptor' => filled($level['descriptor'] ?? null) ? $level['descriptor'] : null,
                        ]);
                    }
                }
            }

            return $rubric;
        });
    }
}
