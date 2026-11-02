<?php

namespace App\Console\Commands;

use App\Models\Subject;
use App\Models\Task;
use App\Support\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Raises the deadline notifications the scope calls for.
 *
 * Nothing a student does can trigger these — a deadline passes because time
 * moves, not because anybody clicked anything — so they need a scheduled pass.
 * See routes/console.php for when it runs and the README for the cron entry the
 * server needs.
 *
 * Two passes over the same set of unfinished, dated tasks:
 *
 *   due soon   the deadline is today or within the configured lead time
 *   overdue    the deadline is behind us and the task is still not done
 *
 * Both are deduplicated by the Notifier against (recipient, type, task), so the
 * command is safe to run daily, twice in a day, or by hand while testing: a
 * task that is still overdue a week later has already been announced and says
 * nothing more. That property is what makes the daily schedule tolerable —
 * a bell that repeats itself is one people stop reading.
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'collabspace:deadline-reminders
                            {--days= : Override the "due soon" lead time in days}
                            {--dry-run : Report what would be sent without writing anything}';

    protected $description = 'Notify members about tasks falling due and tasks already overdue';

    public function handle(Notifier $notifier): int
    {
        $days = (int) ($this->option('days') ?? config('notifications.deadline_reminder_days'));

        if ($days < 0) {
            $this->error('The lead time cannot be negative.');

            return self::FAILURE;
        }

        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $dueSoon = $this->pending()
            ->whereDate('deadline', '>=', $today->toDateString())
            ->whereDate('deadline', '<=', $horizon->toDateString())
            ->get();

        $overdue = $this->pending()
            ->whereDate('deadline', '<', $today->toDateString())
            ->get();

        $projects = $this->projectsDueSoon($today);

        $this->line(sprintf(
            'Due within %d %s: %d · already overdue: %d · projects approaching: %d',
            $days,
            str('day')->plural($days),
            $dueSoon->count(),
            $overdue->count(),
            $projects->count(),
        ));

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');

            $this->report($dueSoon, $overdue, $projects);

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($dueSoon as $task) {
            $sent += $notifier->taskDueSoon($task);
        }

        // The project deadlines, which no task on any board represents: the subject's own, or each assessment's.
        foreach ($projects as $item) {
            $sent += $notifier->projectDueSoon($item['subject'], $item['assessment']);
        }

        $notifyLecturer = (bool) config('notifications.notify_lecturer_on_overdue');

        foreach ($overdue as $task) {
            $sent += $notifier->taskOverdue($task);

            if ($notifyLecturer && $task->group !== null) {
                $sent += $notifier->overdueForLecturer($task, $task->group);
            }
        }

        $this->info($sent === 0
            ? 'Nothing new to announce — everyone has already been told.'
            : sprintf('%d %s sent.', $sent, str('notification')->plural($sent)));

        return self::SUCCESS;
    }

    /**
     * Unfinished tasks that carry a deadline.
     *
     * A task with no deadline cannot be late, and a finished one is nobody's
     * problem however late it was.
     */
    protected function pending()
    {
        return Task::query()
            ->whereNotNull('deadline')
            ->where('status', '!=', Task::STATUS_DONE)
            ->with(['group.subject', 'assignee'])
            ->orderBy('deadline');
    }

    /**
     * Subjects whose project deadline is approaching.
     *
     * Its own lead time, and a longer one: a task on a board is a day or two of
     * work, a semester project is not. Past deadlines are left out — the point
     * is a warning, and a project that is already late is a conversation with
     * the lecturer rather than a notification.
     */
    protected function projectsDueSoon(Carbon $today)
    {
        $lead = (int) config('notifications.project_reminder_days');

        $from = $today->copy()->startOfDay();
        $to = $today->copy()->addDays($lead)->startOfDay();
        $items = [];

        // A subject with no groups has nobody to tell.
        foreach (Subject::query()->with('assessments')->whereHas('groups')->get() as $subject) {
            if ($subject->assessments->count() > 1) {
                // Several assessments: every one with a due date gets its own reminder (and its own once-only record).
                foreach ($subject->assessments as $assessment) {
                    $items[] = ['subject' => $subject, 'assessment' => $assessment, 'due' => $assessment->due_on];
                }
            } else {
                // One (or none): exactly as before, the subject's own project deadline.
                $items[] = ['subject' => $subject, 'assessment' => null, 'due' => $subject->projectDeadline()];
            }
        }

        // Undated and past ones are left out: the point is a warning, not a record of what was missed.
        return collect($items)
            ->filter(fn (array $i) => $i['due'] !== null && $i['due']->copy()->startOfDay()->gte($from) && $i['due']->copy()->startOfDay()->lte($to))
            ->sortBy(fn (array $i) => $i['due']->timestamp)
            ->values();
    }

    /** @param  \Illuminate\Support\Collection<int, Task>  $dueSoon */
    protected function report($dueSoon, $overdue, $projects): void
    {
        $rows = $dueSoon->toBase()->map(fn (Task $t) => ['Due soon', ...$this->row($t)])
            ->merge($overdue->map(fn (Task $t) => ['Overdue', ...$this->row($t)]))
            ->merge($projects->map(fn (array $i) => [
                'Project',
                $i['subject']->code,
                str(($i['assessment'] ? $i['assessment']->title.' · ' : '').$i['subject']->name)->limit(40)->value(),
                $i['due']->format('Y-m-d'),
                'Everyone in the subject',
            ]));

        if ($rows->isEmpty()) {
            return;
        }

        $this->table(['State', 'Group', 'Task', 'Deadline', 'Assigned to'], $rows->all());
    }

    /** @return list<string> */
    protected function row(Task $task): array
    {
        return [
            $task->group?->name ?? '—',
            str($task->title)->limit(40)->value(),
            $task->deadline instanceof Carbon ? $task->deadline->format('Y-m-d') : (string) $task->deadline,
            $task->assignee?->name ?? 'Unassigned (whole group)',
        ];
    }
}
