<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deadline Reminder Lead Time
    |--------------------------------------------------------------------------
    |
    | How many days ahead of a task's deadline the "due soon" reminder goes out.
    | 2 means a task due on Friday is announced on Wednesday, which leaves a
    | working day to act on it without announcing work that is still a week off.
    |
    | Each reminder is sent at most once per task per person, so raising this
    | only moves the announcement earlier — it does not send more of them.
    |
    */

    'deadline_reminder_days' => 2,

    /*
    |--------------------------------------------------------------------------
    | Project Deadline Lead Time
    |--------------------------------------------------------------------------
    |
    | The same idea for the subject's whole project deadline, but further out.
    | A task on the board is a day or two of work, so two days' notice is fair
    | warning; a semester project is not something anyone can start on the
    | Wednesday before, and a reminder that arrived then would read as a taunt.
    |
    | Sent once per student per subject, like every other reminder here.
    |
    */

    'project_reminder_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Overdue Reminders For Lecturers
    |--------------------------------------------------------------------------
    |
    | Whether the lecturer who owns the subject is told when a task in one of
    | their groups misses its deadline. On by default: catching slippage early
    | is the whole point of the monitoring screens.
    |
    */

    'notify_lecturer_on_overdue' => true,

];
