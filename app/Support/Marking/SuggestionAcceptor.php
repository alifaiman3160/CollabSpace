<?php

namespace App\Support\Marking;

use App\Models\Group;
use App\Models\MarkApproval;
use App\Models\Rubric;
use App\Models\User;

/**
 * "Accept suggestions": saves the rule-based level for each System-suggested
 * criterion that has one, as an ordinary saved choice, so the audit record shows
 * where each level came from exactly as if the lecturer had ticked it.
 *
 * It only ever fills gaps. A criterion the lecturer has already decided is left as
 * it is, a criterion with nothing to accept (Manual, or too little data) is left
 * for the lecturer, and it never approves anything.
 */
class SuggestionAcceptor
{
    public function __construct(
        protected MarkSheet $sheets,
        protected ChoiceRecorder $choices,
    ) {}

    /** The levels that would be accepted for this student: criterion id => level id. */
    public function pending(Rubric $rubric, Group $group, User $student): array
    {
        $levels = [];

        foreach ($this->sheets->build($rubric, $group, $student)['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['criterion']->isSuggested() && $row['level'] === null && $row['preselect_id'] !== null) {
                    $levels[$row['criterion']->id] = $row['preselect_id'];
                }
            }
        }

        return $levels;
    }

    /**
     * @return int how many levels were saved (0 when there was nothing to accept, or the marks are approved)
     */
    public function accept(User $by, Rubric $rubric, Group $group, User $student): int
    {
        if ($this->approved($rubric, $group, $student)) {
            return 0;
        }

        $levels = $this->pending($rubric, $group, $student);

        if ($levels === [] || $this->choices->save($by, $rubric, $group, $student, $levels) !== []) {
            return 0;
        }

        return count($levels);
    }

    /**
     * Every student of the group who is not approved.
     *
     * @return array{students: int, levels: int}
     */
    public function acceptForGroup(User $by, Rubric $rubric, Group $group): array
    {
        $students = 0;
        $levels = 0;

        foreach ($group->members as $student) {
            $saved = $this->accept($by, $rubric, $group, $student);

            if ($saved > 0) {
                $students++;
                $levels += $saved;
            }
        }

        return ['students' => $students, 'levels' => $levels];
    }

    protected function approved(Rubric $rubric, Group $group, User $student): bool
    {
        return MarkApproval::active()
            ->where('rubric_id', $rubric->id)->where('group_id', $group->id)->where('student_id', $student->id)
            ->exists();
    }
}
