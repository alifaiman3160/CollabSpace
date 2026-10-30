# Timezone

CollabSpace runs in **Asia/Kuala_Lumpur** (UTC+8, no daylight saving). It is set by `APP_TIMEZONE` in `.env`
(default in `config/app.php`). Everything that shows a time, and every rule that asks "which day is it", uses it.

## Where the calendar day matters

| Rule | Where | Day boundary |
| --- | --- | --- |
| Daily message cap (`message_cap_per_day`) | `ActivityRecorder::dailyMessageCapReached` | Midnight in the app zone |
| Task overdue (a task is on time until its due day ends) | `Task::isOverdue`, `Task::scopeOverdue` | End of the due day, app zone |
| Deadline reminders (due soon / overdue) | `SendDeadlineReminders`, `Notifier` | Start of today, app zone |
| "Days late" and overdue counts in marking facts | `GroupFacts` | Start of the day, app zone |
| Days to a project deadline | `Subject::daysLeft`, `x-table.date` | Start of today, app zone |
| Chat and notification day headings, "Today" | `GroupChat`, `NotificationList` | App zone |
| AI daily allowance (`ai_quota.day`) | `AiQuota` | Midnight in the app zone |
| Reports, CSV exports (`Generated`, approval time) | `ContributionReport`, `EvidenceReport` | App zone |

Before this change the app zone was UTC, so all of the above rolled over at 08:00 Malaysia time, and times were shown
eight hours early. The overdue check also treated a task as overdue from 00:00 on its due day; it now waits for the day to end.

## Existing data

A database date-time has **no zone**. Laravel writes and reads the text in the app zone, and nothing converts it. So changing
`APP_TIMEZONE` does **not** move stored values; it changes what they are taken to mean. A row saved under UTC as
`2026-10-30 18:57:00` is now read as 18:57 Malaysia time, eight hours too early (it was really 02:57 on 31 Oct).

Date-only columns (task deadline, project deadline) are days, not instants, and are unaffected.

### Local test data

Either:

1. **Throw it away** (simplest): `php artisan migrate:fresh --seed`. New rows are written in Malaysia time.
2. **Keep it, shift it once**:

   ```
   php artisan app:shift-stored-times --dry-run     # shows each column and its row count
   php artisan app:shift-stored-times               # adds 8 hours to every stored date-time
   ```

   It records that it has run and refuses to run again (`--force` overrides), because a second run would shift everything twice.
   Back up first (`mysqldump`). Use `--from` and `--to` if the old zone was not UTC.

### MySQL note

If the database server's own zone differs from the app zone, the text still round-trips (Laravel never sets the session zone),
and no application code relies on `CURRENT_TIMESTAMP` defaults: every created/updated/approved time is written by PHP.
Do not set `time_zone` on the connection after data exists: MySQL `TIMESTAMP` columns would then convert on read.
