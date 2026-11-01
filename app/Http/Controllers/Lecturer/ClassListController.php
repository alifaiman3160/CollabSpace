<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Enrolment;
use App\Models\GroupMember;
use App\Models\Subject;
use Illuminate\View\View;

/**
 * The class list of one subject, read only: who the administrator enrolled, section by section, and who still has no
 * group. Editing enrolment belongs to the administrator, so nothing here changes anything.
 */
class ClassListController extends LecturerController
{
    public function show(Subject $subject): View
    {
        $subject = $this->ownedSubject($subject);

        $sections = $subject->sections()->orderBy('name')->get();

        $enrolments = Enrolment::query()
            ->where('subject_id', $subject->getKey())
            ->with('user:id,name,email,matric_no,is_active')
            ->get()
            ->filter(fn (Enrolment $e) => $e->user !== null);

        // The group each enrolled student is in for this subject (one at most), from one query.
        $groupOf = GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->where('groups.subject_id', $subject->getKey())
            ->pluck('groups.name', 'group_members.user_id');

        return view('lecturer.subjects.class-list', [
            'subject' => $subject,
            'sections' => $sections,
            'bySection' => $enrolments->groupBy('section_id')->map(fn ($rows) => $rows->sortBy(fn ($e) => mb_strtolower($e->user->name))->values()),
            'groupOf' => $groupOf,
            'total' => $enrolments->count(),
            'ungrouped' => $enrolments->reject(fn ($e) => $groupOf->has($e->user_id))->count(),
        ]);
    }
}
