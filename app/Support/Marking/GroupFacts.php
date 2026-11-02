<?php

namespace App\Support\Marking;

use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Group;
use App\Models\Task;

/**
 * The facts a suggestion is based on, worked out in code for every member of a
 * group at once.
 *
 * Counts of messages, document edits and uploads are read from activity_logs,
 * not from the messages or documents tables, for the same reason the
 * contribution report is: the log is where the scoring rules (the daily message
 * cap, the removal of credit when a task leaves Done) have already been applied.
 * A deleted document is only hidden, and what it earned stays in the log, so edits
 * on a deleted document still count: that is the point of keeping them. Reading the source tables
 * would hand back the uncapped figure and quietly bypass them.
 *
 * Task facts come from the tasks table, because deadlines live there, but a
 * task only counts as completed if the log also records the completion for that
 * student. A task that is done but whose credit was withdrawn is not evidence.
 *
 * For one assessment of a subject that has several, only what falls inside that assessment's dates counts: the log
 * entries by their time, and the tasks by when they were created. Nothing is changed in the log; this is only a filter on
 * what is read. With a single assessment (or none) everything counts, as before.
 *
 * Averages are over the group's current members, so a student is compared with
 * the group as it stands.
 */
class GroupFacts
{
    /** The per-student figures, in the order they are shown and sent. */
    public const KEYS = [
        'tasks_assigned',
        'tasks_completed',
        'tasks_completed_on_time',
        'tasks_completed_late',
        'tasks_overdue',
        'messages_sent',
        'document_edits',
        'files_uploaded',
    ];

    /**
     * @return array<int, array<string, mixed>>  facts keyed by user id
     */
    public function forGroup(Group $group, ?Assessment $assessment = null): array
    {
        $group->loadMissing('members');
        $memberIds = $group->members->pluck('id')->all();

        if ($memberIds === []) {
            return [];
        }

        $today = now()->startOfDay();

        // Credit as recorded: task id => the user it was credited to.
        $credited = Assessment::limit(ActivityLog::query(), $assessment)
            ->where('group_id', $group->id)
            ->where('activity_type', 'task_completed')
            ->get(['user_id', 'reference_id'])
            ->mapWithKeys(fn ($log) => [$log->reference_id => $log->user_id]);

        $tasks = Assessment::limit(Task::query(), $assessment)
            ->where('group_id', $group->id)
            ->whereIn('assigned_to', $memberIds)
            ->get()
            ->groupBy('assigned_to');

        // Counts out of the log, as the contribution report does.
        $logs = Assessment::limit(ActivityLog::query(), $assessment)
            ->where('group_id', $group->id)
            ->whereIn('user_id', $memberIds)
            ->whereIn('activity_type', ['message_sent', 'document_created', 'document_edited', 'file_uploaded'])
            ->selectRaw('user_id, activity_type, COUNT(*) AS entries')
            ->groupBy('user_id', 'activity_type')
            ->get()
            ->groupBy('user_id');

        $facts = [];

        foreach ($memberIds as $id) {
            $mine = $tasks->get($id, collect());
            $counts = $logs->get($id, collect());

            $completed = $mine->filter(fn (Task $t) => $t->status === Task::STATUS_DONE
                && $t->completed_at !== null
                && ($credited[$t->id] ?? null) === $id);

            $onTime = 0;
            $lateDays = [];

            foreach ($completed as $task) {
                if ($task->deadline !== null && $task->completed_at->copy()->startOfDay()->gt($task->deadline->copy()->startOfDay())) {
                    $lateDays[] = (int) $task->deadline->copy()->startOfDay()->diffInDays($task->completed_at->copy()->startOfDay());
                } else {
                    $onTime++;
                }
            }

            $overdue = $mine->filter(fn (Task $t) => $t->status !== Task::STATUS_DONE
                && $t->deadline !== null
                && $t->deadline->copy()->startOfDay()->lt($today))->count();

            $facts[$id] = [
                'tasks_assigned' => $mine->count(),
                'tasks_completed' => $completed->count(),
                'tasks_completed_on_time' => $onTime,
                'tasks_completed_late' => count($lateDays),
                'late_days' => $lateDays,
                'tasks_overdue' => $overdue,
                'messages_sent' => (int) $counts->where('activity_type', 'message_sent')->sum('entries'),
                'document_edits' => (int) $counts->whereIn('activity_type', ['document_created', 'document_edited'])->sum('entries'),
                'files_uploaded' => (int) $counts->where('activity_type', 'file_uploaded')->sum('entries'),
            ];
        }

        $size = count($facts);

        $average = [];
        $total = [];
        foreach (self::KEYS as $key) {
            $total[$key] = array_sum(array_column($facts, $key));
            $average[$key] = round($total[$key] / $size, 2);
        }

        // Plain context only: which assessment these figures are for, when the subject has several. It is not a fact and
        // is never judged on (EvidenceFacts only passes on the names a criterion is allowed to use).
        $label = $assessment !== null && $assessment->subject?->assessments->count() > 1 ? $assessment->title : null;

        foreach ($facts as $id => $own) {
            if ($label !== null) {
                $facts[$id]['assessment'] = $label;
            }

            $facts[$id]['group_average'] = $average;
            $facts[$id]['group_total'] = $total;
            $facts[$id]['group_size'] = $size;
        }

        return $facts;
    }
}
