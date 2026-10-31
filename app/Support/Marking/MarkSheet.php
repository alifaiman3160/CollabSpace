<?php

namespace App\Support\Marking;

use App\Models\Group;
use App\Models\MarkChoice;
use App\Models\MarkSectionMethod;
use App\Models\MarkSuggestion;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\RubricLevel;
use App\Models\RubricSection;
use App\Models\User;
use App\Support\Rubric\RubricScoring;
use Illuminate\Support\Collection;

/**
 * One student's marking sheet for one rubric: what is chosen, what is still
 * undecided, and what the totals are if the choices stand.
 *
 * A section is complete only when EVERY criterion in it has a level the lecturer
 * has chosen, Manual ones included, its scoring formula is settled, and, for a
 * section that mixes System-suggested and Manual criteria, the lecturer has said
 * how it is totalled. Until then it has marks so far and no total: undecided is
 * not the same as zero, and nothing downstream (the approval) may treat it so.
 *
 * Marks are computed only by RubricSection::marksFor, which goes through
 * RubricScoring. Suggested levels are advice and never count: only a saved
 * choice does.
 */
class MarkSheet
{
    /**
     * @return array{
     *     sections: list<array<string, mixed>>,
     *     complete: bool,
     *     total: ?float,
     *     so_far: float,
     *     maximum: ?float,
     *     blockers: list<string>,
     *     decided: int,
     *     criteria: int,
     * }
     */
    public function build(Rubric $rubric, Group $group, User $student): array
    {
        $rubric->loadMissing('sections.criteria.levels');

        $choices = MarkChoice::query()
            ->where('rubric_id', $rubric->id)->where('group_id', $group->id)->where('student_id', $student->id)
            ->get()->keyBy('rubric_criterion_id');

        $suggestions = MarkSuggestion::query()
            ->where('group_id', $group->id)->where('student_id', $student->id)
            ->whereIn('rubric_criterion_id', $rubric->criteria()->pluck('rubric_criteria.id'))
            ->get()->keyBy('rubric_criterion_id');

        $methods = MarkSectionMethod::query()
            ->where('rubric_id', $rubric->id)->where('group_id', $group->id)
            ->get()->keyBy('rubric_section_id');

        $sections = [];
        $blockers = [];
        $total = 0.0;
        $soFar = 0.0;
        $decided = 0;
        $count = 0;
        $scaledPending = 0;
        $maximum = 0.0;
        $maximumKnown = true;
        $complete = $rubric->sections->isNotEmpty();

        foreach ($rubric->sections as $section) {
            $built = $this->section($section, $choices, $suggestions, $methods->get($section->id)?->method);

            $sections[] = $built;
            $blockers = array_merge($blockers, $built['blockers']);
            $decided += $built['decided'];
            $count += $built['criteria_count'];

            if ($built['maximum'] === null) {
                $maximumKnown = false;
            } else {
                $maximum += $built['maximum'];
            }

            // A section scaled from its System-suggested criteria has no honest
            // running figure until all of them are chosen: scaling a few of them up
            // to the section's full marks would read as nearly final when it is not.
            if ($built['so_far'] === null) {
                $scaledPending++;
            } else {
                $soFar += $built['so_far'];
            }

            if ($built['complete']) {
                $total += $built['total'];
            } else {
                $complete = false;
            }
        }

        return [
            'sections' => $sections,
            'complete' => $complete,
            'total' => $complete ? round($total, 2) : null,
            'so_far' => round($soFar, 2),
            'maximum' => $maximumKnown && $rubric->sections->isNotEmpty() ? round($maximum, 2) : null,
            'scaled_pending' => $scaledPending,
            'blockers' => $blockers,
            'decided' => $decided,
            'criteria' => $count,
        ];
    }

    /**
     * @param  Collection<int, MarkChoice>  $choices
     * @param  Collection<int, MarkSuggestion>  $suggestions
     * @return array<string, mixed>
     */
    protected function section(RubricSection $section, Collection $choices, Collection $suggestions, ?string $method): array
    {
        $formula = $section->scoring;
        $hasSuggested = $section->criteria->contains(fn ($c) => $c->isSuggested());
        $hasManual = $section->criteria->contains(fn ($c) => ! $c->isSuggested());
        $mixed = $hasSuggested && $hasManual;

        $maximum = $section->maximumMarks();

        $method = $mixed && in_array($method, [MarkSectionMethod::ADD, MarkSectionMethod::SCALE], true) ? $method : null;

        // A section marked on level marks alone (the weight is the maximum) has its
        // System-suggested and Manual marks on one scale, so adding them is the natural
        // reading and is the default until the lecturer chooses otherwise. A section
        // that multiplies by weight has no such obvious default and must be chosen.
        $defaulted = $mixed && $method === null && $formula === RubricScoring::LEVEL_ONLY;

        if ($defaulted) {
            $method = MarkSectionMethod::ADD;
        }

        // In a scaled section only the System-suggested criteria count towards the
        // total. A Manual criterion there can still be given a level, for the
        // lecturer's own record, but it is not part of the total and never holds
        // up completing it.
        $scaled = $mixed && $method === MarkSectionMethod::SCALE;

        $rows = [];
        $counted = 0;
        $decided = 0;
        $sumAll = 0.0;
        $sumSuggested = 0.0;
        $maxSuggested = 0.0;

        foreach ($section->criteria as $criterion) {
            $row = $this->criterion($section, $criterion, $choices->get($criterion->id), $suggestions->get($criterion->id));
            $row['counts'] = ! ($scaled && ! $criterion->isSuggested());
            $rows[] = $row;

            if ($formula !== null && $criterion->isSuggested()) {
                $maxSuggested += RubricScoring::marks($formula, $criterion->weight, RubricScoring::topLevel([
                    'levels' => $criterion->levels->map(fn ($l) => ['marks' => $l->marks])->all(),
                ]));
            }

            if ($row['counts']) {
                $counted++;

                if ($row['level'] !== null) {
                    $decided++;
                }
            }

            if ($row['level'] !== null && $row['marks'] !== null) {
                $sumAll += $row['marks'];

                if ($criterion->isSuggested()) {
                    $sumSuggested += $row['marks'];
                }
            }
        }

        $undecided = $counted - $decided;
        $scaling = null;

        if ($scaled && $maximum !== null && $maxSuggested > 0) {
            $scaling = [
                'suggested_marks' => round($sumSuggested, 2),
                'suggested_maximum' => round($maxSuggested, 2),
                'section_maximum' => $maximum,
                'factor' => $maximum / $maxSuggested,
            ];
        }

        $blockers = [];

        if ($formula === null) {
            $blockers[] = "“{$section->title}”: choose how its marks are calculated on the rubric before marking it.";
        }

        if ($mixed && $method === null) {
            $blockers[] = "“{$section->title}”: choose how this section is totalled (add the manual entries, or scale the System-suggested ones).";
        }

        if ($scaled && $formula !== null && $scaling === null) {
            $blockers[] = "“{$section->title}”: there are no System-suggested marks to scale.";
        }

        if ($undecided > 0) {
            $what = $scaled ? 'System-suggested criterion still needs' : 'criterion still needs';
            $whatMany = $scaled ? 'System-suggested criteria still need' : 'criteria still need';

            $blockers[] = "“{$section->title}”: ".$undecided.' '.($undecided === 1 ? $what : $whatMany).' a level.';
        }

        $complete = $blockers === [];

        // Marks so far. Null, not zero and not a scaled-up fraction, for a scaled
        // section that is not finished.
        $soFar = match (true) {
            $formula === null => 0.0,
            $scaled && ! $complete => null,
            $scaling !== null => round($sumSuggested * $scaling['factor'], 2),
            default => round($sumAll, 2),
        };
        return [
            'section' => $section,
            'rows' => $rows,
            'scaled' => $scaled,
            'mixed' => $mixed,
            'method' => $method,
            'method_defaulted' => $defaulted,
            'scaling' => $scaling,
            'criteria_count' => $counted,
            'decided' => $decided,
            'undecided' => $undecided,
            'so_far' => $soFar,
            'total' => $complete ? $soFar : null,
            'maximum' => $maximum,
            'complete' => $complete,
            'formula_chosen' => $formula !== null,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function criterion(RubricSection $section, RubricCriterion $criterion, ?MarkChoice $choice, ?MarkSuggestion $s): array
    {
        $levels = $criterion->levels->sortBy([['marks', 'asc'], ['position', 'asc']])->values();
        $chosen = $choice ? $levels->firstWhere('id', $choice->rubric_level_id) : null;

        $marks = $chosen !== null && $section->scoring !== null ? $section->marksFor($criterion, $chosen) : null;

        // The one level that may be ticked in advance: the rule-based one, and only
        // where its confidence is not "none". The AI's level is never preselected.
        $preselect = $chosen === null && $s !== null && ! $s->insufficient_data && $s->confidence !== 'none' && $s->baseline_level_id !== null
            ? $s->baseline_level_id
            : null;

        $reason = null;

        if ($chosen === null) {
            $reason = match (true) {
                $s?->insufficient_data => $s->needs_input_reason,
                $s?->needs_input => $s->needs_input_reason,
                ! $criterion->isSuggested() => 'Manual: you choose the level.',
                default => null,
            };
        }

        return [
            'criterion' => $criterion,
            'levels' => $levels,
            'choice' => $choice,
            'level' => $chosen,
            'marks' => $marks,
            'suggestion' => $s,
            'baseline_level' => $s?->baseline_level_id ? $levels->firstWhere('id', $s->baseline_level_id) : null,
            'ai_level' => $s?->ai_level_id ? $levels->firstWhere('id', $s->ai_level_id) : null,
            'preselect_id' => $preselect,
            'selected_id' => $chosen?->id ?? $preselect,
            'undecided_reason' => $reason,
            'insufficient' => (bool) $s?->insufficient_data,
            'differs' => (bool) $s?->differs,
        ];
    }

    /**
     * The sheet as plain data, to be frozen into an approval and read back for
     * the evidence report. Nothing in it refers to a row that can later change.
     *
     * @param  array<string, mixed>  $sheet  from build()
     * @return array<string, mixed>
     */
    public function snapshot(Rubric $rubric, array $sheet): array
    {
        $label = fn (?RubricLevel $l) => $l === null ? null : ['id' => $l->id, 'label' => $l->label, 'marks' => (float) $l->marks, 'range' => $l->marks_range];

        return [
            'rubric' => ['id' => $rubric->id, 'name' => $rubric->name, 'version' => $rubric->version],
            'total' => $sheet['total'],
            'sections' => array_map(function (array $s) use ($label) {
                /** @var RubricSection $section */
                $section = $s['section'];

                return [
                    'id' => $section->id,
                    'title' => $section->title,
                    'mode' => $section->mode,
                    'formula' => $section->scoring,
                    'formula_text' => $section->scoringExplained(),
                    'declared_total' => $section->declared_total === null ? null : (float) $section->declared_total,
                    'maximum' => $s['maximum'],
                    'method' => $s['method'],
                    'scaling' => $s['scaling'],
                    'total' => $s['total'],
                    'criteria' => array_map(function (array $r) use ($label) {
                        $c = $r['criterion'];
                        $sug = $r['suggestion'];
                        $choice = $r['choice'];

                        return [
                            'id' => $c->id,
                            'name' => $c->name,
                            'counts' => $r['counts'],
                            'weight' => $c->weight === null ? null : (float) $c->weight,
                            'mode' => $c->mode,
                            'evidence_type' => $c->evidence_type,
                            'level' => $label($r['level']),
                            'marks' => $r['marks'],
                            'source' => $choice?->source,
                            'override_reason' => $choice?->override_reason,
                            'baseline' => $label($r['baseline_level']),
                            'baseline_note' => $sug?->baseline_note,
                            'ai' => $label($r['ai_level']),
                            'ai_rationale' => $sug?->ai_rationale,
                            'ai_status' => $sug?->ai_status,
                            'insufficient_data' => $r['insufficient'],
                            'needs_input_reason' => $sug?->needs_input_reason,
                            'confidence' => $sug?->confidence,
                            'differs' => $r['differs'],
                            'facts' => $sug?->facts,
                            // The records line as worded when this was approved, so a later change of wording never rewrites it.
                            'facts_text' => $c->isSuggested() && is_array($sug?->facts) ? EvidenceFacts::describe($c->evidence_type, $sug->facts) : null,
                            'provider' => $sug?->provider,
                            'model' => $sug?->model,
                            'run_id' => $sug?->marking_run_id,
                        ];
                    }, $s['rows']),
                ];
            }, $sheet['sections']),
        ];
    }
}
