<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Document;
use App\Models\Group;
use App\Models\Message;
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

        if ($recipients->isEmpty()) {
            return;
        }

        $body = $message();
        $now = now();

        // One insert rather than a row at a time — a busy group chat would
        // otherwise issue a query per member per message.
        AppNotification::insert($recipients->map(fn ($userId) => [
            'user_id' => $userId,
            'type' => $type,
            'message' => Str::limit($body, 500, ''),
            'link' => $link,
            'is_read' => false,
            'created_at' => $now,
        ])->all());
    }

    protected function write(int $userId, string $type, string $message, string $link): void
    {
        $notification = new AppNotification([
            'user_id' => $userId,
            'type' => $type,
            'message' => Str::limit($message, 500, ''),
            'link' => $link,
            'is_read' => false,
        ]);

        $notification->created_at = now();
        $notification->save();
    }
}
