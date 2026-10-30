<?php

namespace App\Support\Marking;

use App\Models\Group;
use App\Models\MarkApproval;
use App\Models\MarkChoice;
use App\Models\MarkSectionMethod;
use App\Models\MarkSuggestion;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records the levels a lecturer chooses for one student.
 *
 * A choice is the lecturer's decision, kept with the suggestions as they stood
 * when it was made. If it is the AI's level or the rule-based level it is
 * recorded as accepting that; if it is neither, while a suggestion existed, it is
 * an override and a reason is required, because "the system said X and I chose Y"
 * needs saying why. A criterion with nothing suggested needs no reason.
 *
 * All or nothing: if any choice is refused none is saved, so the lecturer never
 * has a half-saved sheet. A student whose marks are approved cannot be edited
 * until the approval is revoked.
 */
class ChoiceRecorder
{
    /**
     * @param  array<int|string, int|string|null>  $levels  criterion id => level id ("" or null clears it)
     * @param  array<int|string, string|null>  $reasons  criterion id => override reason
     * @param  array<int|string, string|null>  $methods  section id => add | scale ("" clears it)
     * @return array<string, string> errors keyed by field, empty when everything was saved
     */
    public function save(User $by, Rubric $rubric, Group $group, User $student, array $levels, array $reasons = [], array $methods = []): array
    {
        $rubric->loadMissing('sections.criteria.levels');

        if (MarkApproval::active()->where('rubric_id', $rubric->id)->where('group_id', $group->id)->where('student_id', $student->id)->exists()) {
            return ['approved' => 'These marks are approved. Revoke the approval to change them.'];
        }

        $criteria = $rubric->sections->flatMap->criteria->keyBy('id');
        $suggestions = MarkSuggestion::query()
            ->where('group_id', $group->id)->where('student_id', $student->id)
            ->whereIn('rubric_criterion_id', $criteria->keys())
            ->get()->keyBy('rubric_criterion_id');

        $errors = [];
        $writes = [];

        foreach ($levels as $criterionId => $levelId) {
            /** @var RubricCriterion|null $criterion */
            $criterion = $criteria->get((int) $criterionId);

            if ($criterion === null) {
                continue;   // not this rubric's criterion: ignored, never trusted
            }

            if ($levelId === null || $levelId === '') {
                $writes[$criterion->id] = null;

                continue;
            }

            $level = $criterion->levels->firstWhere('id', (int) $levelId);

            if ($level === null) {
                $errors["levels.{$criterion->id}"] = 'Choose one of this criterion\'s own levels.';

                continue;
            }

            $s = $suggestions->get($criterion->id);
            $reason = trim((string) ($reasons[$criterion->id] ?? ''));

            $source = match (true) {
                $s?->ai_level_id === $level->id => MarkChoice::AI,
                $s?->baseline_level_id === $level->id => MarkChoice::RULE_BASED,
                default => MarkChoice::LECTURER,
            };

            $hadSuggestion = $s !== null && ($s->ai_level_id !== null || $s->baseline_level_id !== null);

            if ($source === MarkChoice::LECTURER && $hadSuggestion && $reason === '') {
                $errors["reasons.{$criterion->id}"] = "Say why “{$criterion->name}” differs from the suggestion.";

                continue;
            }

            if (mb_strlen($reason) > 1000) {
                $errors["reasons.{$criterion->id}"] = 'Keep the reason under 1000 characters.';

                continue;
            }

            $writes[$criterion->id] = [
                'rubric_level_id' => $level->id,
                'baseline_level_id' => $s?->baseline_level_id,
                'ai_level_id' => $s?->ai_level_id,
                'source' => $source,
                'override_reason' => $source === MarkChoice::LECTURER && $reason !== '' ? $reason : null,
            ];
        }

        $mixed = $rubric->sections->filter(fn ($section) => $section->criteria->contains(fn ($c) => $c->isSuggested())
            && $section->criteria->contains(fn ($c) => ! $c->isSuggested()))->keyBy('id');

        foreach ($methods as $sectionId => $method) {
            if (! $mixed->has((int) $sectionId)) {
                continue;
            }

            if ($method !== '' && $method !== null && ! in_array($method, [MarkSectionMethod::ADD, MarkSectionMethod::SCALE], true)) {
                $errors["methods.{$sectionId}"] = 'Choose to add the manual entries or to scale the System-suggested ones.';
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        DB::transaction(function () use ($by, $rubric, $group, $student, $writes, $methods, $mixed) {
            foreach ($writes as $criterionId => $data) {
                $where = ['rubric_id' => $rubric->id, 'group_id' => $group->id, 'student_id' => $student->id, 'rubric_criterion_id' => $criterionId];

                if ($data === null) {
                    MarkChoice::where($where)->delete();
                } else {
                    MarkChoice::updateOrCreate($where, $data + ['chosen_by' => $by->id]);
                }
            }

            foreach ($methods as $sectionId => $method) {
                if (! $mixed->has((int) $sectionId)) {
                    continue;
                }

                $where = ['rubric_id' => $rubric->id, 'group_id' => $group->id, 'rubric_section_id' => (int) $sectionId];

                if ($method === '' || $method === null) {
                    MarkSectionMethod::where($where)->delete();
                } else {
                    MarkSectionMethod::updateOrCreate($where, ['method' => $method, 'set_by' => $by->id]);
                }
            }
        });

        return [];
    }
}
