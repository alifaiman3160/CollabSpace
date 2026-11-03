<?php

namespace App\Http\Controllers\Student;

use App\Models\Assessment;
use App\Models\Group;
use App\Models\Submission;
use App\Support\Submissions\SubmissionException;
use App\Support\Submissions\Submissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A group handing in its report for an assessment: any member may submit, submit again and withdraw. Lecturers and admins
 * never get here (the student role middleware), and a group that is not the student's own is a 404.
 */
class SubmissionController extends StudentController
{
    public function __construct(protected Submissions $submissions) {}

    public function store(Request $request, Group $group): RedirectResponse
    {
        $group = $this->ownedGroup($group)->loadMissing('subject');
        $back = redirect()->to(route('student.documents.group', $group).'#submit');

        $validated = $request->validate([
            'assessment_id' => ['required', 'integer', Rule::exists('assessments', 'id')->where('subject_id', $group->subject_id)],
            'documents' => ['required', 'array', 'min:1', 'max:'.Submissions::MAX_DOCUMENTS],
            'documents.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'assessment_id.required' => 'Choose the assessment you are submitting for.',
            'assessment_id.exists' => 'Choose one of this subject\'s assessments.',
            'documents.required' => 'Choose at least one document to submit.',
            'documents.min' => 'Choose at least one document to submit.',
            'documents.max' => 'A submission can hold at most '.Submissions::MAX_DOCUMENTS.' documents.',
            'note.max' => 'Keep the note to 255 characters.',
        ]);

        $assessment = Assessment::query()->where('subject_id', $group->subject_id)->findOrFail($validated['assessment_id']);

        try {
            $submission = $this->submissions->submit($group, $assessment, $validated['documents'], $validated['note'] ?? null, $this->student());
        } catch (SubmissionException $e) {
            return $back->withErrors(['submission' => $e->getMessage()])->withInput();
        }

        return $back->with('status', "Submitted {$assessment->title}".($submission->is_late ? ' (marked late).' : ' on time.'));
    }

    public function withdraw(Submission $submission): RedirectResponse
    {
        $group = $this->ownedGroup($submission->group()->firstOrFail());
        $back = redirect()->to(route('student.documents.group', $group).'#submit');

        try {
            $this->submissions->withdraw($submission, $this->student());
        } catch (SubmissionException $e) {
            return $back->withErrors(['submission' => $e->getMessage()]);
        }

        return $back->with('status', 'The submission was withdrawn. Its PDF is kept as history.');
    }
}
