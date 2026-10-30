<?php

use App\Console\Commands\SendDeadlineReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Deadline notifications.
|
| Once a day, early enough that a reminder is waiting when people start work
| and late enough that nobody is woken by it. Deadlines are whole dates rather
| than times, so the hour only decides when the bell appears, not what it says.
|
| Deliberately no withoutOverlapping(). That would take a lock out of the cache
| store, which on this project is the database — so merely listing the schedule
| would need a live connection, and a run killed mid-flight would leave a stale
| lock that silently skips the next day's reminders. The command is already
| idempotent: the Notifier refuses to tell anyone about a task twice, so two
| overlapping runs cost a few wasted queries and nothing else. That is the
| cheaper failure by a distance.
|
| This needs one cron entry on the server, or the schedule never runs:
|
|     * * * * * cd /path/to/collabspace && php artisan schedule:run >> /dev/null 2>&1
|
| On Windows, a Task Scheduler entry running the same command every minute.
*/
Schedule::command(SendDeadlineReminders::class)->dailyAt('07:00');
