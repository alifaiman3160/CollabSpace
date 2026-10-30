<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Document;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes in-app notifications.
 *
 * One place, so the wording and the link targets stay consistent and every
 * caller records the same shape of row. Notifications are informational only —
 * nothing reads them back to make a decision, so a failure here must never take
 * down the action that triggered it.
 */
class Notifier
{
    /** A student has been given a task. */
    public function taskAssigned(Task $task, User $actor): void
    {
        if ($task->assigned_to === null || $task->assigned_to === $actor->getKey()) {
            return;   // Assigning yourself needs no announcement.
        }

        $this->write(
            $task->assigned_to,
            'task_assigned',
            "{$actor->name} assigned you: {$task->title}",
            route('student.board.show', $task->group_id, false),
        );
    }

    public function documentCreated(Document $document, User $actor): void
    {
        $this->fanOut(
            $document->group_id,
            $actor,
            'document_created',
            fn () => "{$actor->name} created “{$document->title}”",
            route('student.documents.edit', $document->getKey(), false),
        );
    }

    public function documentEdited(Document $document, User $actor): void
    {
        $this->fanOut(
            $document->group_id,
            $actor,
            'document_edited',
            fn () => "{$actor->name} edited “{$document->title}”",
            route('student.documents.edit', $document->getKey(), false),
        );
    }

    /** A chat message. The preview is trimmed so the row stays readable. */
    public function messagePosted(Message $message, User $actor): void
    {
        $preview = $message->body !== null
            ? Str::limit(trim($message->body), 70)
            : ($message->file_name ? 'sent a file: '.$message->file_name : 'sent a message');

        $body = $message->body !== null ? "{$actor->name}: {$preview}" : "{$actor->name} {$preview}";

        $this->fanOut(
            $message->group_id,
            $actor,
            'chat_message',
            fn () => $body,
            route('student.chat.show', $message->group_id, false),
        );
    }

    /** A lecturer has put a student into a group. */
    public function addedToGroup(User $student, Group $group, User $actor): void
    {
        $group->loadMissing('subject');

        $this->write(
            $student->getKey(),
            'group_assigned',
            "{$actor->name} added you to {$group->name} ({$group->subject?->code})",
            route('student.workspace.show', $group->getKey(), false),
        );
    }

    /** A student has been taken out of a group. */
    public function removedFromGroup(User $student, Group $group, User $actor): void
    {
        $group->loadMissing('subject');

        $this->write(
            $student->getKey(),
            'group_removed',
            "{$actor->name} removed you from {$group->name} ({$group->subject?->code})",
            route('student.dashboard', absolute: false),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Deadlines
    |--------------------------------------------------------------------------
    |
    | Raised by the scheduled command, not by anything a user does — see
    | App\Console\Commands\SendDeadlineReminders. Each one goes out at most once
    | per task per person, so running the schedule daily does not nag.
    |
    */

    /** A task is coming up. Returns how many people were told. */
    public function taskDueSoon(Task $task): int
    {
        $when = $task->deadline->isToday()
            ? 'today'
            : ($task->deadline->isTomorrow() ? 'tomorrow' : 'on '.$task->deadline->format('j M'));

        return $this->writeOnce(
            $this->recipientsFor($task),
            'deadline',
            $task->getKey(),
            fn () => "Due {$when}: {$task->title}",
            route('student.board.show', $task->group_id, false),
        );
    }

    /** A task has gone past its deadline and is still not done. */
    public function taskOverdue(Task $task): int
    {
        // Cast: Carbon returns a float here, and both sides are midnight so the
        // difference is always whole — but "overdue by 3.0 days" would be an
        // ugly way to find out otherwise.
        $days = (int) $task->deadline->diffInDays(now()->startOfDay());
        $late = $days <= 1 ? 'since yesterday' : "by {$days} days";

        return $this->writeOnce(
            $this->recipientsFor($task),
            'task_overdue',
            $task->getKey(),
            fn () => "Overdue {$late}: {$task->title}",
            route('student.board.show', $task->group_id, false),
        );
    }

    /**
     * The whole project is coming up, as opposed to one task on the board.
     *
     * Goes to every student in every group under the subject, once each. Keyed
     * on the subject rather than the task, and given its own type, so it cannot
     * collide with a task reminder that happens to share the id — reference_id
     * is a plain column pointing at whichever table the type implies.
     */
    public function projectDueSoon(Subject $subject): int
    {
        $days = $subject->daysToProjectDeadline();

        if ($days === null || $days < 0) {
            return 0;
        }

        $when = match (true) {
            $days === 0 => 'is due today',
            $days === 1 => 'is due tomorrow',
            default => "is due in {$days} days",
        };

        return $this->writeOnce(
            $this->studentsOf($subject),
            'project_deadline',
            $subject->getKey(),
            fn () => "{$subject->code} — the group project {$when} ({$subject->project_deadline->format('j M')})",
            // The dashboard, because that is where the brief and the countdown
            // are: a student following this link lands on what they have to do,
            // not merely on the fact that it is due.
            route('student.dashboard', absolute: false),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Lecturers
    |--------------------------------------------------------------------------
    |
    | A lecturer is an observer here, not a participant, so they hear about the
    | two things that change what they would do next: work slipping, and work
    | finishing. Chat and document traffic is deliberately left out — a lecturer
    | teaching several groups would drown in it, and the monitoring screen shows
    | that far better than a list of bells ever could.
    |
    */

    /** An overdue task in a group the lecturer supervises. */
    public function overdueForLecturer(Task $task, Group $group): int
    {
        $lecturerId = $this->lecturerFor($task->group_id);

        if ($lecturerId === null) {
            return 0;
        }

        $group->loadMissing('subject');
        $label = $group->subject?->code ?? 'a subject';

        return $this->writeOnce(
            [$lecturerId],
            'task_overdue',
            $task->getKey(),
            fn () => "{$group->name} ({$label}) has missed a deadline: {$task->title}",
            route('lecturer.progress.show', $group->getKey(), false),
        );
    }

    /**
     * Every task on a group's board is finished.
     *
     * Raised as the last one moves into Done, and keyed on the group so it is
     * announced once — a group that adds a task and finishes it again says
     * nothing further.
     */
    public function groupCompleted(Group $group, int $taskCount): void
    {
        $lecturerId = $this->lecturerFor($group->getKey());

        if ($lecturerId === null) {
            return;
        }

        $group->loadMissing('subject');
        $label = $group->subject?->code ?? 'a subject';

        $this->writeOnce(
            [$lecturerId],
            'group_completed',
            $group->getKey(),
            fn () => "{$group->name} ({$label}) has completed all {$taskCount} of its tasks",
            route('lecturer.progress.show', $group->getKey(), false),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Administrators
    |--------------------------------------------------------------------------
    |
    | Account changes go to the other administrators, never to the one who made
    | them — telling you what you just did is noise. On a deployment with a
    | single administrator these are therefore silent by design, and the one
    | below is the one that actually fires: it is raised by the account holder,
    | so every administrator hears it.
    |
    */

    /** Somebody has signed in and replaced the temporary password issued them. */
    public function accountActivated(User $user): void
    {
        $this->writeMany(
            $this->adminIds(),
            'account_activated',
            fn () => "{$user->name} has signed in and set their own password",
            route('admin.users.edit', $user->getKey(), false),
            $user->getKey(),
        );
    }

    public function accountCreated(User $user, User $actor): void
    {
        $this->writeMany(
            $this->adminIds($actor),
            'account_created',
            fn () => "{$actor->name} created a {$user->role} account for {$user->name}",
            route('admin.users.edit', $user->getKey(), false),
            $user->getKey(),
        );
    }

    public function accountStatusChanged(User $user, User $actor): void
    {
        $verb = $user->is_active ? 'reactivated' : 'deactivated';

        $this->writeMany(
            $this->adminIds($actor),
            'account_status',
            fn () => "{$actor->name} {$verb} {$user->name}'s account",
            route('admin.users.edit', $user->getKey(), false),
            $user->getKey(),
        );
    }

    public function passwordIssued(User $user, User $actor): void
    {
        $this->writeMany(
            $this->adminIds($actor),
            'account_password',
            fn () => "{$actor->name} issued a new temporary password for {$user->name}",
            route('admin.users.edit', $user->getKey(), false),
            $user->getKey(),
        );
    }

    /**
     * Notifies every member of a group except the person who acted.
     *
     * @param  \Closure():string  $message
     */
    protected function fanOut(int $groupId, User $actor, string $type, \Closure $message, string $link): void
    {
        $recipients = Group::find($groupId)?->groupMembers()
            ->where('user_id', '!=', $actor->getKey())
            ->pluck('user_id') ?? collect();

        $this->writeMany($recipients->all(), $type, $message, $link);
    }

    /**
     * Writes one row each for a list of recipients.
     *
     * @param  list<int>  $userIds
     * @param  \Closure():string  $message
     */
    protected function writeMany(array $userIds, string $type, \Closure $message, string $link, ?int $referenceId = null): int
    {
        if ($userIds === []) {
            return 0;
        }

        $body = $message();
        $now = now();

        // One insert rather than a row at a time — a busy group chat would
        // otherwise issue a query per member per message.
        AppNotification::insert(array_map(fn ($userId) => [
            'user_id' => $userId,
            'type' => $type,
            'reference_id' => $referenceId,
            'message' => Str::limit($body, 500, ''),
            'link' => $link,
            'is_read' => false,
            'created_at' => $now,
        ], $userIds));

        return count($userIds);
    }

    protected function write(int $userId, string $type, string $message, string $link, ?int $referenceId = null): void
    {
        $notification = new AppNotification([
            'user_id' => $userId,
            'type' => $type,
            'reference_id' => $referenceId,
            'message' => Str::limit($message, 500, ''),
            'link' => $link,
            'is_read' => false,
        ]);

        $notification->created_at = now();
        $notification->save();
    }

    /**
     * Writes to the recipients who have not already been told about this
     * record, and returns how many rows were actually written.
     *
     * This is what makes the scheduled reminders safe to run every day, and
     * safe to run twice by hand: a task that is still overdue tomorrow is
     * already accounted for and says nothing further. Without it the command
     * would repeat itself daily until someone dealt with the task, which trains
     * people to ignore the bell.
     *
     * @param  list<int>  $userIds
     * @param  \Closure():string  $message
     */
    protected function writeOnce(array $userIds, string $type, int $referenceId, \Closure $message, string $link): int
    {
        if ($userIds === []) {
            return 0;
        }

        $alreadyTold = AppNotification::query()
            ->about($type, $referenceId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        return $this->writeMany(
            array_values(array_diff($userIds, $alreadyTold)),
            $type,
            $message,
            $link,
            $referenceId,
        );
    }

    /**
     * Who should hear about a task: the member it belongs to, or everyone in
     * the group when it belongs to nobody.
     *
     * An unassigned task with a deadline is precisely the one at risk of being
     * forgotten, so silence is the wrong answer — it goes to the whole group
     * and whoever picks it up owns it.
     *
     * @return list<int>
     */
    protected function recipientsFor(Task $task): array
    {
        if ($task->assigned_to !== null) {
            return [$task->assigned_to];
        }

        return Group::find($task->group_id)?->groupMembers()->pluck('user_id')->all() ?? [];
    }

    /**
     * Every student sitting in any group under a subject, once each.
     *
     * A student belongs to at most one group per subject, so the unique() is
     * belt and braces rather than load-bearing — but a second membership would
     * otherwise mean a second reminder about the same project.
     *
     * @return list<int>
     */
    protected function studentsOf(Subject $subject): array
    {
        return GroupMember::query()
            ->whereIn('group_id', $subject->groups()->select('id'))
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /** The lecturer who owns the subject this group sits under. */
    protected function lecturerFor(int $groupId): ?int
    {
        return Group::with('subject')->find($groupId)?->subject?->lecturer_id;
    }

    /** @return list<int> */
    protected function adminIds(?User $except = null): array
    {
        return User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->pluck('id')
            ->all();
    }
}
