<?php

/*
|--------------------------------------------------------------------------
| Evidence-based mark suggestions
|--------------------------------------------------------------------------
|
| Everything that tunes how recorded activity becomes a suggested level lives
| here, so the bands can be justified and adjusted without touching code.
|
| A suggestion is only ever a suggestion: the lecturer decides.
|
*/

return [

    /*
    | Bands: how a figure becomes a level.
    |
    | Each evidence type reduces a student's facts to one ratio (see Baseline).
    | `cutoffs` are ascending ratios; the ratio's band is how many of them it
    | has reached, so 0.25, 0.5, 0.75, 0.9 makes five bands. A band is then
    | placed on the criterion's levels BY POSITION, lowest to highest, so the
    | same bands serve a rubric with three levels or seven. The lowest band is
    | the lowest level and the highest band is the highest level.
    |
    |   task_completion  share of assigned tasks completed
    |   reliability      share of assigned tasks completed on time (a task that
    |                    is late or still overdue is not on time, so it simply
    |                    does not count in the share)
    |   communication    messages sent, relative to the group average
    |   collaboration    document edits and files uploaded, relative to the
    |                    group average
    |
    | The two shares (task_completion, reliability) are absolute: 1.0 means every
    | assigned task, so a high bar is right and 0.9 is the top band.
    |
    | The two relative types (communication, collaboration) compare a student
    | with the group, where 1.0 means "did what the average member did". Meeting
    | the average is a sound result but not an exceptional one, so it sits at
    | level 4 of 5, and the top level needs clearly more than average. Both use
    | the same cutoffs. On a five-level rubric:
    |
    |     ratio to the group average      level
    |     1.25 or more                    5  (top)
    |     0.9 up to, not including 1.25   4
    |     0.6 up to, not including 0.9    3
    |     0.3 up to, not including 0.6    2
    |     below 0.3                       1
    |
    | A ratio exactly on a cutoff has reached it (1.25 is level 5, 0.9 is level
    | 4). With fewer or more than five levels the same bands are spread evenly
    | along them, lowest to highest. That keeps the average below the top level
    | on four or more levels (level 3 of 4, level 5 of 7); on only two or three
    | levels the five bands cannot all be told apart and the average reaches the
    | top one.
    */
    'bands' => [
        'task_completion' => ['cutoffs' => [0.25, 0.5, 0.75, 0.9]],
        'reliability' => ['cutoffs' => [0.25, 0.5, 0.75, 0.9]],
        'communication' => ['cutoffs' => [0.3, 0.6, 0.9, 1.25]],
        'collaboration' => ['cutoffs' => [0.3, 0.6, 0.9, 1.25]],
    ],

    /*
    | Minimum group activity.
    |
    | A ratio against the group average is only as steady as the activity behind
    | it. With an average of 0.75 uploads, one extra upload moves a student from
    | "Satisfactory" to "Excellent": the result looks precise and is noise. So for
    | each evidence type, the WHOLE GROUP must have recorded at least this much
    | before a level is suggested. Below it, no level is suggested at all (neither
    | from the baseline nor from the AI) and the lecturer is told why. At exactly
    | the minimum, the suggestion is made normally.
    |
    | Totals are counts of what is recorded, after the existing rules (such as the
    | daily message cap) have been applied, added up over every current member:
    |
    |   task_completion  tasks assigned to anyone in the group
    |   reliability      tasks assigned to anyone in the group (the same tasks,
    |                    judged on when they were done)
    |   communication    chat messages that counted
    |   collaboration    document edits plus file uploads
    |
    | Raise a value to be more cautious with small groups, lower it to trust
    | smaller samples, and set 0 to switch the guard off for that type.
    */
    'min_group_activity' => [
        // Four tasks across the group: fewer than that, "3 of 4 done" says little.
        'task_completion' => 4,
        // The same four tasks: whether they were on time needs the same footing.
        'reliability' => 4,
        // Six counted messages: a group that has barely spoken has no norm to compare with.
        'communication' => 6,
        // Six edits and uploads: the figure most easily moved by a single upload.
        'collaboration' => 6,
    ],

    /*
    | A baseline and an AI suggestion that are further apart than this many
    | levels are flagged for the lecturer's attention.
    */
    'flag_gap_levels' => 1,

    'ai' => [
        // The longest rationale accepted. Anything longer is discarded and that
        // criterion falls back to the baseline.
        'rationale_max' => 300,

        // Pause between one student's request and the next, in milliseconds,
        // so a large group does not burst past a provider's rate limit.
        'spacing_ms' => 500,

        // Attempts per student when the provider answers 429 (too many
        // requests), including the first. After the last, the rest of the
        // group is left with rule-based suggestions.
        'max_attempts' => 3,

        // Wait before a retry when the provider gives no Retry-After: this many
        // milliseconds, doubling each time, never more than max_wait_ms.
        'backoff_ms' => 1000,
        'max_wait_ms' => 30000,

        // The marking page asks for one student per request. Each request makes one
        // provider call, held to this many seconds, so it ends well inside what a
        // browser or PHP's web time limit will wait. It never sleeps through a 429.
        'web_timeout_seconds' => 20,

        // On a 429 the page waits, in the browser, for the time the provider asked
        // for (never more than this) and asks again for that student.
        'retry_wait_cap_seconds' => 60,

        // How many times the page tries a rate-limited student before marking them
        // failed with a Retry button. The student keeps their rule-based suggestion.
        'web_max_attempts' => 3,
    ],
];
