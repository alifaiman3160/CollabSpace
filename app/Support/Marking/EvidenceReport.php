<?php

namespace App\Support\Marking;

use App\Models\Group;
use App\Models\MarkApproval;
use App\Models\MarkSuggestion;
use App\Models\Rubric;
use App\Support\Rubric\RubricScoring;

/**
 * The evidence report for a group: for every student and criterion, the facts, the
 * rule-based level, the AI's level, the level finally chosen, why it differs,
 * the marks, and how each total was reached (formula, and any scaling).
 *
 * Approved students are read from their approval, so the report shows what was
 * signed off whatever has happened since. The rest are read as they stand now and
 * marked as a draft.
 *
 * Text that came from a file, a provider or a person is written so a
 * spreadsheet cannot run it as a formula.
 */
class EvidenceReport
{
    public function __construct(protected MarkSheet $sheets) {}

    /** @return list<list<string|int|float>> */
    public function rows(Rubric $rubric, Group $group): array
    {
        $rubric->loadMissing('sections.criteria.levels', 'subject');
        $group->loadMissing('members', 'subject', 'section');

        $criterionIds = $rubric->criteria()->pluck('rubric_criteria.id');

        $providers = MarkSuggestion::query()
            ->where('group_id', $group->id)->whereIn('rubric_criterion_id', $criterionIds)
            ->whereNotNull('provider')->get(['provider', 'model'])
            ->map(fn ($s) => $s->provider.' / '.$s->model)->unique()->values();

        $approvals = MarkApproval::active()
            ->where('rubric_id', $rubric->id)->where('group_id', $group->id)
            ->with('approver')->get()->keyBy('student_id');

        $rows = [
            ['CollabSpace marking evidence report'],
            [],
            ['Group', $this->text($group->name)],
            ['Subject', $this->text($group->subject->code.' - '.$group->subject->name)],
            // The class section (1A, 1B). Named so it is not mistaken for the rubric's "Section" column below.
            ['Class section', $this->text($group->section?->name ?? '')],
            ['Rubric', $this->text($rubric->name)],
            ['Rubric version', $rubric->version],
            ['Generated', now()->format('Y-m-d H:i:s')],
            ['AI provider and model used', $providers->isEmpty() ? 'None (rule-based suggestions only)' : $this->text($providers->implode('; '))],
            ['Students', $group->members->count()],
            [],
            ['How to read this. Suggestions are advice. The chosen level is the lecturer\'s decision, and a mark is final only for a student shown as Approved.'],
            ['Where a section mixes System-suggested and Manual criteria, "Section method" says how it was totalled: "add" counts every criterion; "scale" scores the System-suggested criteria and scales that total to the section\'s full marks (the Scaling column shows the working).'],
            [],
            [
                'Student', 'Matric no.', 'Status', 'Section', 'Scoring formula', 'Section method', 'Criterion', 'Decided by', 'Evidence',
                'Facts', 'Rule-based level', 'Rule-based reason', 'AI-suggested level', 'AI reason', 'Flag (differ by more than one level)',
                'Chosen level', 'Chosen as', 'Override reason', 'Counts towards total', 'Marks', 'Section total', 'Section maximum', 'Scaling', 'Student total',
                'Approved by', 'Approved at',
            ],
        ];

        foreach ($group->members->sortBy('name') as $student) {
            $approval = $approvals->get($student->id);

            $snapshot = $approval?->snapshot
                ?? $this->sheets->snapshot($rubric, $this->sheets->build($rubric, $group, $student));

            $status = $approval ? 'Approved' : 'Draft (not approved)';

            foreach ($snapshot['sections'] as $section) {
                foreach ($section['criteria'] as $c) {
                    $rows[] = [
                        $this->text($student->name),
                        $this->text($student->matric_no ?? ''),
                        $status,
                        $this->text($section['title']),
                        $this->formula($section['formula']),
                        $this->method($section),
                        $this->text($c['name']),
                        $c['mode'] === 'suggested' ? 'System-suggested' : 'Manual',
                        $c['mode'] === 'suggested' ? EvidenceType::label($c['evidence_type']) : '',
                        $this->text($this->facts($c)),
                        $this->text($this->level($c['baseline'])),
                        $this->text($c['insufficient_data'] ? 'Insufficient data: '.$c['needs_input_reason'] : (string) $c['baseline_note']),
                        $this->text($this->level($c['ai'])),
                        $this->text((string) ($c['ai_rationale'] ?? '')),
                        $c['differs'] ? 'YES' : '',
                        $this->text($this->level($c['level'])),
                        $this->source($c['source']),
                        $this->text((string) ($c['override_reason'] ?? '')),
                        ($c['counts'] ?? true) ? 'yes' : 'no (section is scaled from its System-suggested criteria)',
                        $c['marks'] ?? '',
                        $section['total'] ?? 'incomplete',
                        $section['maximum'] ?? '',
                        $this->scaling($section['scaling']),
                        $snapshot['total'] ?? 'incomplete',
                        $approval ? $this->text($approval->approver?->name ?? '') : '',
                        $approval ? $approval->approved_at->format('Y-m-d H:i:s') : '',
                    ];
                }
            }
        }

        return $rows;
    }

    private function formula(?string $formula): string
    {
        return $formula === null ? 'Not chosen' : RubricScoring::label($formula);
    }

    /** @param  array<string, mixed>  $section */
    private function method(array $section): string
    {
        return match ($section['method']) {
            'add' => 'add (every criterion counted)',
            'scale' => 'scale (System-suggested criteria, scaled to the section\'s full marks)',
            default => '',
        };
    }

    /** @param  array<string, float>|null  $scaling */
    private function scaling(?array $scaling): string
    {
        if ($scaling === null) {
            return '';
        }

        return RubricScoring::trim($scaling['suggested_marks']).' of '.RubricScoring::trim($scaling['suggested_maximum'])
            .' from System-suggested criteria, x '.RubricScoring::trim($scaling['section_maximum']).' / '.RubricScoring::trim($scaling['suggested_maximum'])
            .' = '.RubricScoring::trim(round($scaling['suggested_marks'] * $scaling['factor'], 2));
    }

    /** @param  array<string, mixed>|null  $level */
    private function level(?array $level): string
    {
        return $level === null ? '' : $level['label'].' ('.($level['range'] ?: RubricScoring::trim($level['marks'])).')';
    }

    private function source(?string $source): string
    {
        return match ($source) {
            'ai' => 'Accepted AI-suggested level',
            'rule_based' => 'Accepted rule-based level',
            'lecturer' => 'Lecturer\'s own choice',
            default => '',
        };
    }

    /** The facts the criterion was judged on, as one cell. @param  array<string, mixed>  $c */
    private function facts(array $c): string
    {
        if ($c['mode'] !== 'suggested' || ! is_array($c['facts'] ?? null)) {
            return '';
        }

        // An approval keeps the line it was approved with; one made before that was recorded is read in the old wording.
        return $c['facts_text'] ?? EvidenceFacts::describeLegacy($c['evidence_type'], $c['facts']);
    }

    /** Neutralises a leading =, +, - or @ so a spreadsheet shows it as text. */
    private function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
