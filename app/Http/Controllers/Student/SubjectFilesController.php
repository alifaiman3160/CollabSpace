<?php

namespace App\Http\Controllers\Student;

use App\Models\Subject;
use App\Support\SubjectFiles;
use Illuminate\View\View;

/**
 * A subject's files for a student who may read them, reached from the dashboard card (this is how a student who has
 * no group yet gets to them). A subject the student is neither enrolled in nor grouped in is simply not found.
 */
class SubjectFilesController extends StudentController
{
    public function index(Subject $subject): View
    {
        abort_unless(SubjectFiles::canRead($this->student(), $subject), 404);

        $files = $subject->files()->with('targets:assessments.id,assessments.title,assessments.position')->orderBy('kind')->orderBy('title')->get();

        // Grouped by assessment in the order the lecturer set, the whole-subject files last.
        $groups = $subject->assessments->map(fn ($assessment) => ['title' => $assessment->title, 'files' => $files->where('assessment_id', $assessment->id)->values()])
            ->push(['title' => 'Course materials', 'files' => $files->whereNull('assessment_id')->values()])
            ->filter(fn ($group) => $group['files']->isNotEmpty())
            ->values();

        return view('student.subject-files', [
            'subject' => $subject,
            'files' => $files,
            'groups' => $groups,
        ]);
    }
}
