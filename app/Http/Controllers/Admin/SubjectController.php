<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubjectRequest;
use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\Subject;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Sections\SectionException;
use App\Support\Sections\SectionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Subjects belong to the administrator: it creates them, gives each to the lecturer who teaches it, and keeps its
 * sections. A lecturer only fills in the details of the subjects given to them.
 */
class SubjectController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $lecturerId = $request->integer('lecturer') ?: null;

        $subjects = Subject::query()
            ->with(['lecturer:id,name,email', 'sections' => fn ($q) => $q->orderBy('name')])
            ->withCount(['enrolments', 'groups'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhereIn('lecturer_id', User::query()->where('name', 'like', "%{$search}%")->select('id'));
                });
            })
            ->when($lecturerId, fn ($query) => $query->where('lecturer_id', $lecturerId))
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        return view('admin.subjects.index', [
            'subjects' => $subjects,
            'search' => $search,
            'lecturerId' => $lecturerId,
            'lecturers' => $this->lecturers(),
        ]);
    }

    public function create(): View
    {
        return view('admin.subjects.create', ['subject' => null, 'lecturers' => $this->lecturers()]);
    }

    public function store(SubjectRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $subject = DB::transaction(function () use ($validated) {
            $subject = Subject::create([
                'lecturer_id' => $validated['lecturer_id'],
                'code' => $validated['code'],
                'name' => $validated['name'],
                'group_size' => 4,
            ]);

            SectionManager::sync($subject, $validated['sections'] ?? []);

            // Every subject has at least one assessment: "Project", until its lecturer adds the others.
            \App\Models\Assessment::defaultFor($subject);

            return $subject;
        });

        $this->notifier->subjectAssigned($subject, auth()->user());

        return redirect()->route('admin.subjects')
            ->with('status', "{$subject->code} — {$subject->name} has been created and given to {$subject->lecturer->name}.");
    }

    public function edit(Subject $subject): View
    {
        $subject->load(['sections' => fn ($q) => $q->withCount(['enrolments', 'groups'])->orderBy('name')])
            ->loadCount(['enrolments', 'groups']);

        return view('admin.subjects.edit', ['subject' => $subject, 'lecturers' => $this->lecturers()]);
    }

    public function update(SubjectRequest $request, Subject $subject): RedirectResponse
    {
        $validated = $request->validated();
        $changesLecturer = (int) $validated['lecturer_id'] !== $subject->lecturer_id;

        // Moving a subject that already has groups or students to another lecturer is allowed, but only on purpose.
        if ($changesLecturer && ($subject->groups()->exists() || $subject->enrolments()->exists()) && ! $request->boolean('confirm_lecturer')) {
            return back()->withInput()->withErrors([
                'confirm_lecturer' => 'Tick the box to confirm the change of lecturer: this subject already has groups or students.',
            ]);
        }

        try {
            DB::transaction(function () use ($subject, $validated) {
                $subject->update([
                    'lecturer_id' => $validated['lecturer_id'],
                    'code' => $validated['code'],
                    'name' => $validated['name'],
                ]);

                SectionManager::sync($subject, $validated['sections'] ?? []);
            });
        } catch (SectionException $e) {
            // Nothing was saved, not even the other fields.
            return back()->withInput()->withErrors(['sections' => $e->getMessage()]);
        }

        if ($changesLecturer) {
            $this->notifier->subjectAssigned($subject->refresh(), auth()->user());
        }

        return redirect()->route('admin.subjects')->with('status', "{$subject->code} has been updated.");
    }

    /** Only an empty subject can go: no groups and nobody enrolled. */
    public function destroy(Subject $subject): RedirectResponse
    {
        $label = "{$subject->code} — {$subject->name}";

        if ($subject->groups()->exists() || $subject->enrolments()->exists()) {
            return back()->withErrors([
                'status' => "{$label} cannot be deleted: it still has groups or enrolled students. Remove those first.",
            ]);
        }

        DB::transaction(function () use ($subject) {
            AppNotification::query()
                ->where('type', Announcement::TYPE)
                ->whereIn('reference_id', $subject->announcements()->select('id'))
                ->delete();

            \App\Support\SubjectFiles::deleteAll($subject);
            $subject->delete();
        });

        return redirect()->route('admin.subjects')->with('status', "{$label} has been deleted.");
    }

    /** Lecturer accounts only, for the dropdown. */
    protected function lecturers()
    {
        return User::query()->where('role', 'lecturer')->orderBy('name')->get(['id', 'name', 'email', 'is_active']);
    }
}
