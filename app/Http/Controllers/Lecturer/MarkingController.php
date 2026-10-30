<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Group;
use App\Models\MarkApproval;
use App\Models\Rubric;
use App\Models\User;
use App\Support\Ai\AiGateway;
use App\Support\Marking\ApprovalService;
use App\Support\Marking\ChoiceRecorder;
use App\Support\Marking\EvidenceReport;
use App\Support\Marking\MarkingException;
use App\Support\Marking\MarkSheet;
use App\Support\Marking\SuggestionAcceptor;
use App\Support\Marking\SuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marking: turning evidence and suggestions into marks the lecturer stands behind.
 *
 *   index     each subject's rubric, and its groups to mark
 *   group     the students of one group against one rubric version, and where each stands
 *   student   one student's sheet: suggestions beside the lecturer's choices
 *   save      records the choices (and how mixed sections are totalled). Not final.
 *   accept    saves the suggested level for each System-suggested criterion still undecided. Never approves.
 *   acceptAll the same for every student of the group who is not approved
 *   approve   freezes the sheet as that student's marks. Refused until complete.
 *   revoke    reopens an approved sheet
 *   suggestOne  (re)computes the suggestions for one student; the group page calls it in turn
 *   export    the evidence report as CSV
 *
 * Everything is reached through the rubric's subject, which must be the signed-in
 * lecturer's, and the group and student must belong to it: anything else is a 404.
 * Students never reach any of this, and nothing here is shown to them.
 */
class MarkingController extends LecturerController
{
    public function __construct(
        protected MarkSheet $sheets,
        protected ChoiceRecorder $choices,
        protected ApprovalService $approvals,
    ) {}

    public function index(): View
    {
        $subjects = $this->subjects()
            ->with(['groups' => fn ($q) => $q->withCount('groupMembers as members_count')->orderBy('name')])
            ->orderBy('code')
            ->get();

        $rubrics = Rubric::query()
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->where('is_current', true)
            ->get()
            ->keyBy('subject_id');

        $approved = MarkApproval::active()
            ->whereIn('rubric_id', $rubrics->pluck('id'))
            ->get()
            ->groupBy(fn ($a) => $a->rubric_id.'-'.$a->group_id)
            ->map->count();

        return view('lecturer.marking.index', [
            'subjects' => $subjects,
            'rubrics' => $rubrics,
            'approved' => $approved,
        ]);
    }

    public function group(Rubric $rubric, Group $group, AiGateway $ai): View
    {
        $this->scope($rubric, $group);

        $rows = $group->members->sortBy('name')->values()->map(function (User $student) use ($rubric, $group) {
            $sheet = $this->sheets->build($rubric, $group, $student);
            $approval = MarkApproval::active()
                ->where('rubric_id', $rubric->id)->where('group_id', $group->id)->where('student_id', $student->id)->first();

            return [
                'student' => $student,
                'sheet' => $sheet,
                'approval' => $approval,
                'flags' => collect($sheet['sections'])->flatMap(fn ($s) => $s['rows'])->where('differs', true)->count(),
            ];
        });

        return view('lecturer.marking.group', [
            'rubric' => $rubric->load('subject'),
            'group' => $group,
            'rows' => $rows,
            'ai' => $ai->status(),
            'suggested' => $rubric->criteria()->where('rubric_criteria.mode', 'suggested')->exists(),
            'locked' => $rubric->isLocked(),
        ]);
    }

    public function student(Rubric $rubric, Group $group, User $student, AiGateway $ai): View
    {
        $this->scope($rubric, $group, $student);

        $approval = $this->activeApproval($rubric, $group, $student);

        // The neighbours in the group list's own order, for Previous and Next.
        $members = $group->members->sortBy('name')->values();
        $at = $members->search(fn (User $m) => $m->id === $student->id);

        return view('lecturer.marking.student', [
            'previous' => $at > 0 ? $members[$at - 1] : null,
            'next' => $at !== false ? ($members[$at + 1] ?? null) : null,
            'position' => [(int) $at + 1, $members->count()],
            'rubric' => $rubric->load('subject'),
            'group' => $group,
            'student' => $student,
            'sheet' => $this->sheets->build($rubric, $group, $student),
            'approval' => $approval,
            'ai' => $ai->status(),
        ]);
    }

    public function save(Request $request, Rubric $rubric, Group $group, User $student): RedirectResponse
    {
        $this->scope($rubric, $group, $student);

        $errors = $this->choices->save(
            $this->lecturer(),
            $rubric,
            $group,
            $student,
            (array) $request->input('levels', []),
            (array) $request->input('reasons', []),
            (array) $request->input('methods', []),
        );

        if ($errors !== []) {
            return back()->withInput()->withErrors($errors + ['status' => 'Your choices were not saved. Please look at the notes below.']);
        }

        return redirect()
            ->route('lecturer.marking.student', [$rubric, $group, $student])
            ->with('status', 'Choices saved. These are not final marks until you approve them.');
    }

    /** Saves the suggested level where the lecturer has not chosen one. It does not approve. */
    public function accept(Rubric $rubric, Group $group, User $student, SuggestionAcceptor $acceptor): RedirectResponse
    {
        $this->scope($rubric, $group, $student);

        if ($this->activeApproval($rubric, $group, $student)) {
            return back()->withErrors(['status' => 'These marks are approved. Revoke the approval to change them.']);
        }

        $saved = $acceptor->accept($this->lecturer(), $rubric, $group, $student);

        return redirect()
            ->route('lecturer.marking.student', [$rubric, $group, $student])
            ->with('status', $saved === 0
                ? 'There was no suggestion left to accept. Nothing was changed.'
                : "Accepted {$saved} suggested ".($saved === 1 ? 'level' : 'levels').'. These are not final marks until you approve them.');
    }

    /** The same for the whole group: every student not yet approved. Nothing is approved. */
    public function acceptAll(Rubric $rubric, Group $group, SuggestionAcceptor $acceptor): RedirectResponse
    {
        $this->scope($rubric, $group);

        $done = $acceptor->acceptForGroup($this->lecturer(), $rubric, $group);

        return redirect()
            ->route('lecturer.marking.group', [$rubric, $group])
            ->with('status', $done['levels'] === 0
                ? 'There were no suggestions left to accept. Nothing was changed.'
                : "Accepted {$done['levels']} suggested levels for {$done['students']} ".($done['students'] === 1 ? 'student' : 'students').'. Nothing was approved.');
    }

    public function approve(Rubric $rubric, Group $group, User $student): RedirectResponse
    {
        $this->scope($rubric, $group, $student);

        try {
            $approval = $this->approvals->approve($this->lecturer(), $rubric, $group, $student);
        } catch (MarkingException $e) {
            return back()->withErrors(['status' => trim($e->getMessage().' '.implode(' ', $e->blockers))]);
        }

        return redirect()
            ->route('lecturer.marking.student', [$rubric, $group, $student])
            ->with('status', "{$student->name}'s marks are approved: {$approval->total}.");
    }

    public function revoke(Rubric $rubric, Group $group, User $student): RedirectResponse
    {
        $this->scope($rubric, $group, $student);

        try {
            $this->approvals->revoke($this->lecturer(), $rubric, $group, $student);
        } catch (MarkingException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('lecturer.marking.student', [$rubric, $group, $student])
            ->with('status', 'The approval was revoked. The choices can be changed again.');
    }

    /**
     * Suggestions for ONE student, as JSON, for the group page to call in turn.
     *
     * A request that did the whole group would carry every provider call, and its
     * rate-limit waits, inside one web request: long enough to hit PHP's time
     * limit and to hold up a single-threaded dev server. This does one student,
     * makes at most one provider call, and answers quickly. A 429 comes back as
     * "retry after N seconds" for the page to wait out; it is never slept through
     * here. Whatever happens, the student keeps their rule-based suggestions.
     */
    public function suggestOne(Rubric $rubric, Group $group, User $student, SuggestionService $suggestions): JsonResponse
    {
        $this->scope($rubric, $group, $student);

        $result = $suggestions->suggestForStudent($this->lecturer(), $rubric, $group, $student, true);

        return response()->json(['student' => ['id' => $student->id, 'name' => $student->name]] + $result->toArray());
    }
    public function export(Rubric $rubric, Group $group, EvidenceReport $report): StreamedResponse
    {
        $this->scope($rubric, $group);

        $rows = $report->rows($rubric, $group);
        $filename = str($group->subject->code.' '.$group->name.' v'.$rubric->version)->slug()->value().'-marking-evidence.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel reads UTF-8 names and the × and – in the text correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The rubric belongs to this lecturer's subject, the group to that subject,
     * and the student to that group. Anything else is simply not found.
     */
    protected function scope(Rubric $rubric, Group $group, ?User $student = null): void
    {
        $rubric->loadMissing('subject');
        $group->loadMissing(['members', 'subject']);

        abort_unless($rubric->subject?->lecturer_id === $this->lecturer()->id, 404);
        abort_unless($group->subject_id === $rubric->subject_id, 404);

        if ($student !== null) {
            abort_unless($group->members->contains('id', $student->id), 404);
        }
    }

    protected function activeApproval(Rubric $rubric, Group $group, User $student): ?MarkApproval
    {
        return MarkApproval::active()->with('approver')
            ->where('rubric_id', $rubric->id)->where('group_id', $group->id)->where('student_id', $student->id)
            ->latest('id')->first();
    }
}
