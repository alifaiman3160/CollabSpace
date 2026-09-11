<?php

namespace App\Http\Controllers\Student;

use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BoardController extends StudentController
{
    /**
     * Parameterless entry point, so the sidebar can link to "Task board"
     * without knowing a group id. Lands on the student's first group.
     */
    public function index(): View|RedirectResponse
    {
        $groups = $this->myGroups();

        if ($groups->isEmpty()) {
            return view('student.board', ['hasGroup' => false, 'group' => null]);
        }

        return redirect()->route('student.board.show', $groups->first());
    }

    public function show(Group $group): View
    {
        // Guarded here and again inside the Livewire component, since Livewire
        // round-trips the group id back from the browser on every action.
        $group = $this->ownedGroup($group);

        return view('student.board', [
            'hasGroup' => true,
            'group' => $group->load('subject'),
        ]);
    }
}
