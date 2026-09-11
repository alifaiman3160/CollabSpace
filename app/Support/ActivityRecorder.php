<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Group;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The single place an activity_logs row is written.
 *
 * Both the seeder and the running application record contributions through
 * here, so demo data and live data are produced by the same code and can never
 * drift apart. Weights are read from config/contribution.php and denormalised
 * onto the row, so retuning the weights later does not rewrite history.
 */
class ActivityRecorder
{
    /** Writes one row. `$at` exists so the seeder can backdate. */
    public function record(
        User|int $user,
        Group|int $group,
        string $type,
        ?int $referenceId = null,
        ?CarbonInterface $at = null,
    ): ActivityLog {
        $log = new ActivityLog([
            'user_id' => $user instanceof User ? $user->getKey() : $user,
            'group_id' => $group instanceof Group ? $group->getKey() : $group,
            'activity_type' => $type,
            'reference_id' => $referenceId,
            'weight' => ActivityLog::weightFor($type),
        ]);

        $log->created_at = $at ?? now();
        $log->save();

        return $log;
    }

    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    */

    public function taskCreated(Task $task, User|int $actor, ?CarbonInterface $at = null): ActivityLog
    {
        return $this->record($actor, $task->group_id, 'task_created', $task->getKey(), $at);
    }

    public function taskCompleted(Task $task, User|int $actor, ?CarbonInterface $at = null): ActivityLog
    {
        return $this->record($actor, $task->group_id, 'task_completed', $task->getKey(), $at);
    }

    /**
     * Undoes a completion: used when a task is dragged back out of Done, so the
     * contribution score reflects only work that is actually finished.
     */
    public function taskCompletionCleared(Task $task): int
    {
        return ActivityLog::query()
            ->where('group_id', $task->group_id)
            ->where('activity_type', 'task_completed')
            ->where('reference_id', $task->getKey())
            ->delete();
    }

    /**
     * A deleted task takes its contribution rows with it — there is no
     * cascade for these, because reference_id is a plain column rather than a
     * foreign key (it points at several different tables).
     */
    public function taskDeleted(Task $task): int
    {
        return ActivityLog::query()
            ->where('group_id', $task->group_id)
            ->whereIn('activity_type', ['task_created', 'task_completed'])
            ->where('reference_id', $task->getKey())
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Documents, chat
    |--------------------------------------------------------------------------
    */

    public function documentCreated(Document $document, User|int $actor, ?CarbonInterface $at = null): ActivityLog
    {
        return $this->record($actor, $document->group_id, 'document_created', $document->getKey(), $at);
    }

    public function documentEdited(Document $document, User|int $actor, ?CarbonInterface $at = null): ActivityLog
    {
        return $this->record($actor, $document->group_id, 'document_edited', $document->getKey(), $at);
    }

    /**
     * A deleted document takes its contribution rows with it, the same way
     * taskDeleted() does: reference_id is a plain column rather than a foreign
     * key — it points at several different tables — so nothing cascades for it.
     *
     * Leaving the rows would keep scoring writing that no longer exists, and
     * worse, reference_id would eventually collide with a new document's id and
     * the credit would resurface against the wrong piece of work.
     */
    public function documentDeleted(Document $document): int
    {
        return ActivityLog::query()
            ->where('group_id', $document->group_id)
            ->whereIn('activity_type', ['document_created', 'document_edited'])
            ->where('reference_id', $document->getKey())
            ->delete();
    }

    /**
     * A chat message with text.
     *
     * Returns null once the sender has hit config('contribution.message_cap_per_day')
     * in this group for that day. The message itself is unaffected — it sends
     * and displays like any other; it simply stops earning score, so a run of
     * one-word replies cannot inflate a contribution total.
     *
     * The row is skipped rather than written at weight 0 deliberately: a
     * zero-weight row would surface as "+0 pts" in the group activity feed and
     * expose the cap, and the cap is a scoring rule, not something students
     * should be managing around.
     */
    public function messageSent(Message $message, ?CarbonInterface $at = null): ?ActivityLog
    {
        $at ??= now();

        if ($this->dailyMessageCapReached($message->user_id, $message->group_id, $at)) {
            return null;
        }

        return $this->record($message->user_id, $message->group_id, 'message_sent', $message->getKey(), $at);
    }

    /**
     * A chat message carrying a file. Uploads are not capped — a file is a
     * substantial contribution, and there is no cheap way to spam them.
     *
     * A message with both text and a file writes both rows: they are two
     * distinct contributions, and the caller records each.
     */
    public function fileUploaded(Message $message, ?CarbonInterface $at = null): ActivityLog
    {
        return $this->record($message->user_id, $message->group_id, 'file_uploaded', $message->getKey(), $at);
    }

    /** Has this sender already earned the day's worth of message score here? */
    public function dailyMessageCapReached(int $userId, int $groupId, ?CarbonInterface $at = null): bool
    {
        $cap = (int) config('contribution.message_cap_per_day');

        if ($cap <= 0) {
            return false;
        }

        $at ??= now();

        return ActivityLog::query()
            ->where('user_id', $userId)
            ->where('group_id', $groupId)
            ->where('activity_type', 'message_sent')
            ->whereDate('created_at', $at->toDateString())
            ->count() >= $cap;
    }
}
