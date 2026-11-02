<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Requests\Lecturer\AssessmentRequest;
use App\Models\Assessment;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;

/**
 * The assessments of a subject the lecturer teaches (Assignment 1, Project ...): add, change, delete. Only the owner gets
 * here; another lecturer's subject is a 404. The dates only decide whose activity counts for which assessment; nothing
 * in the activity log is ever changed.
 */
class AssessmentController extends LecturerController
{
    public function store(AssessmentRequest $request, Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $assessment = $subject->assessments()->create($request->validated() + [
            'position' => ((int) $subject->assessments()->max('position')) + 1,
        ]);

        return $this->back($subject, $assessment)->with('status', "{$assessment->title} has been added.");
    }

    public function update(AssessmentRequest $request, Subject $subject, Assessment $assessment): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);
        abort_unless($assessment->subject_id === $subject->getKey(), 404);

        $assessment->update($request->validated());

        return $this->back($subject, $assessment)->with('status', $assessment->hasApprovals()
            ? "{$assessment->title} was updated. Marks already approved keep their frozen snapshot."
            : "{$assessment->title} was updated.");
    }

    public function destroy(Subject $subject, Assessment $assessment): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);
        abort_unless($assessment->subject_id === $subject->getKey(), 404);

        if (($reason = $assessment->deleteBlock()) !== null) {
            return $this->back($subject)->withErrors(['assessment_delete' => "{$assessment->title} cannot be deleted: {$reason}."], 'assessment');
        }

        $title = $assessment->title;
        $assessment->delete();

        return $this->back($subject)->with('status', "{$title} was deleted.");
    }

    /** Back to the edit page, at the block that was changed (or the whole card). */
    protected function back(Subject $subject, ?Assessment $assessment = null): RedirectResponse
    {
        return redirect()->to(route('lecturer.subjects.edit', $subject).'#'.($assessment ? 'assessment-'.$assessment->getKey() : 'assessments'));
    }
}
