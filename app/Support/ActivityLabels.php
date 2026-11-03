<?php

namespace App\Support;

/** What a recorded activity reads as ("completed a task") and its icon, in one place for the pages that list them. */
class ActivityLabels
{
    public const LABELS = [
        'task_created' => 'created a task',
        'task_completed' => 'completed a task',
        'message_sent' => 'posted a message',
        'file_uploaded' => 'uploaded a file',
        'document_created' => 'created a document',
        'document_edited' => 'edited a document',
        'document_deleted' => 'removed a document',
        'submission_submitted' => 'submitted the report',
        'submission_resubmitted' => 'submitted the report again',
        'submission_withdrawn' => 'withdrew the submission',
    ];

    public const ICONS = [
        'task_created' => 'plus',
        'task_completed' => 'check',
        'message_sent' => 'chat',
        'file_uploaded' => 'file',
        'document_created' => 'file',
        'document_edited' => 'edit',
        'document_deleted' => 'trash',
        'submission_submitted' => 'check',
        'submission_resubmitted' => 'check',
        'submission_withdrawn' => 'trash',
    ];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}