<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contribution Scoring Weights
    |--------------------------------------------------------------------------
    |
    | Each recorded activity contributes points toward a member's contribution
    | score. Weights are configurable so the scoring model can be justified and
    | adjusted without touching application code.
    |
    */

    'weights' => [
        'task_completed'   => 5,
        'task_created'     => 2,
        'document_created' => 3,
        'document_edited'  => 3,
        // The audit trail of a deletion: worth nothing, and not evidence (GroupFacts counts only created and edited).
        'document_deleted' => 0,
        // Handing a report in is evidence for display only: worth nothing, never counted as contribution.
        // Who leads a group is a label, not a score: a leader change is worth nothing.
        'leader_changed'         => 0,
        'submission_submitted'   => 0,
        'submission_resubmitted' => 0,
        'submission_withdrawn'   => 0,
        'file_uploaded'    => 2,
        'message_sent'     => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Low Participation Threshold
    |--------------------------------------------------------------------------
    |
    | A member is flagged for low participation when their contribution score
    | falls below this fraction of the group average. 0.5 means a member
    | scoring under half the group average is flagged.
    |
    */

    'low_participation_ratio' => 0.5,

    /*
    |--------------------------------------------------------------------------
    | Message Cap Per Day
    |--------------------------------------------------------------------------
    |
    | Caps how many messages per day count toward the score, so contribution
    | cannot be inflated by sending large volumes of trivial messages.
    |
    */

    'message_cap_per_day' => 20,

];
