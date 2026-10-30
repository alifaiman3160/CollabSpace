<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Group;
use App\Models\Subject;
use App\Support\Formation\BalancedAllocator;
use App\Support\Formation\Pool;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where a lecturer chooses how to form groups for a subject.
 *
 * Shows who still needs placing, then offers the two ways of doing it. Writes
 * nothing, and neither do the screens it leads to until the lecturer confirms.
 */
class FormationController extends LecturerController
{
    public function index(Request $request, Subject $subject): View
    {
        $subject = $this->ownedSubject($subject);

        $pool = Pool::for($subject);

        $groups = Group::query()
            ->where('subject_id', $subject->getKey())
            ->withCount('groupMembers as members_count')
            ->get();

        return view('lecturer.formation.index', [
            'subject' => $subject,
            'pool' => $pool,
            'withCgpa' => $pool->filter(fn ($u) => $u->cgpa !== null)->count(),
            'bounds' => BalancedAllocator::bounds($pool->count(), $subject->group_size),
            'existingGroups' => $groups->count(),
            'emptyGroups' => $groups->where('members_count', 0)->count(),
            // Set by a finished import, to point at whoever it left without a
            // group: those are the people balanced allocation is for.
            'afterImport' => $request->query('after') === 'import',
        ]);
    }
}
