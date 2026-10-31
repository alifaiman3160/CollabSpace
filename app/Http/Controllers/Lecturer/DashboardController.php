<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\ActivityLog;
use App\Support\AnnouncementReach;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\GroupMember;
use App\Models\MarkApproval;
use App\Models\Rubric;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The lecturer's landing page: a card for each subject they teach, the totals, and the groups that
 * need them. Everything is read through the lecturer's own subjects, and every per-subject and
 * per-group figure comes from a fixed number of grouped queries (no query per card or per row).
 */
class DashboardController extends LecturerController
{
    /** Announcements kept per subject card: the strip shows three and "Show earlier" the rest. */
    protected const ANNOUNCEMENTS_PER_SUBJECT = 6;

    public function index(): View
    {
        $subjects = $this->subjects()->orderBy('code')->get();
        $subjectIds = $subjects->modelKeys();

        $groups = $this->groups()
            ->with(['subject', 'members:users.id,users.name'])
            ->withCount([
                'groupMembers as members_count',
                'tasks as tasks_total',
                'tasks as tasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE),
                'tasks as tasks_overdue' => fn ($q) => $q->overdue(),
            ])
            ->orderBy('subject_id')
            ->orderBy('name')
            ->get();

        $groupIds = $groups->modelKeys();

        // A student can sit in several of this lecturer's groups across different subjects, so the overall
        // figure counts them once, and each subject's figure counts them once within that subject.
        $studentsBySubject = GroupMember::query()
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->whereIn('groups.id', $groupIds)
            ->groupBy('groups.subject_id')
            ->selectRaw('groups.subject_id as subject_id, count(distinct group_members.user_id) as total')
            ->pluck('total', 'subject_id');

        $counts = [
            'subjects' => $subjects->count(),
            'groups' => $groups->count(),
            'students' => GroupMember::whereIn('group_id', $groupIds)->distinct()->count('user_id'),
            'completed' => (int) $groups->sum('tasks_done'),
            'overdue' => (int) $groups->sum('tasks_overdue'),
            'needing_attention' => $groups->where('tasks_overdue', '>', 0)->count(),
        ];

        $cards = $subjects->map(function ($subject) use ($groups, $studentsBySubject) {
            $own = $groups->where('subject_id', $subject->id);
            $total = (int) $own->sum('tasks_total');
            $done = (int) $own->sum('tasks_done');

            return [
                'subject' => $subject,
                'groups' => $own->count(),
                'students' => (int) ($studentsBySubject[$subject->id] ?? 0),
                'tasks_total' => $total,
                'tasks_done' => $done,
                'progress' => $total > 0 ? (int) round($done / $total * 100) : 0,
                'overdue' => (int) $own->sum('tasks_overdue'),
            ];
        });

        return view('lecturer.dashboard', [
            'counts' => $counts,
            'cards' => $cards,
            'announcements' => $this->announcements($subjectIds),
            'audience' => app(AnnouncementReach::class)->audience($subjectIds),
            'opened' => app(AnnouncementReach::class)->opened(Announcement::query()->whereIn('subject_id', $subjectIds)->pluck('id')),
            'awaitingMarking' => $this->awaitingMarking($subjects, $studentsBySubject),
            'needingAttention' => \App\Support\GroupNeed::sort($groups),
            'recentActivity' => ActivityLog::query()
                ->whereIn('group_id', $groupIds)
                ->with(['user:id,name', 'group:id,name'])
                ->latest('created_at')
                ->latest('id')
                ->take(15)
                ->get(),
        ]);
    }

    /** The newest announcements of each subject, one query. @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection> */
    protected function announcements(array $subjectIds)
    {
        return Announcement::query()
            ->whereIn('subject_id', $subjectIds)
            ->with('author:id,name')
            ->latest()
            ->latest('id')
            ->get()
            ->groupBy('subject_id')
            ->map(fn ($items) => $items->take(self::ANNOUNCEMENTS_PER_SUBJECT));
    }

    /**
     * Students whose marks are not yet approved, in the subjects that have a current rubric: the same
     * "approved" count the Marking page shows, so the two always agree.
     *
     * @return array{students: int, subjects: int}
     */
    protected function awaitingMarking($subjects, $studentsBySubject): array
    {
        $rubrics = Rubric::query()
            ->whereIn('subject_id', $subjects->modelKeys())
            ->where('is_current', true)
            ->get();

        $approved = MarkApproval::active()
            ->whereIn('rubric_id', $rubrics->modelKeys())
            ->get(['rubric_id', 'student_id'])
            ->groupBy('rubric_id')
            ->map(fn ($rows) => $rows->pluck('student_id')->unique()->count());

        $waiting = $rubrics->sum(fn ($rubric) => max(0, (int) ($studentsBySubject[$rubric->subject_id] ?? 0) - (int) ($approved[$rubric->id] ?? 0)));

        return ['students' => (int) $waiting, 'subjects' => $rubrics->count()];
    }
}
