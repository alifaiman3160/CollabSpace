<?php

namespace App\Support\Marking;

/**
 * Which facts each evidence type may be judged on.
 *
 * A criterion about communication is not evidence about document edits, and one
 * about collaboration is not about chat. So the model is given, for each
 * criterion, only the facts of that criterion's evidence type, by name, and is
 * told to use nothing else. The same list is what a rationale's figures are
 * checked against, so "cites a number" means a number from facts the criterion
 * was allowed to use.
 */
final class EvidenceFacts
{
    /**
     * The student's own facts each type may use, by the names GroupFacts gives them.
     *
     * @var array<string, list<string>>
     */
    private const PERMITTED = [
        // Completed against assigned.
        EvidenceType::TASK_COMPLETION => ['tasks_assigned', 'tasks_completed'],
        // Of the assigned tasks: how many on time, how many late and by how much, how many overdue now.
        EvidenceType::RELIABILITY => ['tasks_assigned', 'tasks_completed_on_time', 'tasks_completed_late', 'late_days', 'tasks_overdue'],
        // Messages only.
        EvidenceType::COMMUNICATION => ['messages_sent'],
        // Document edits and uploads only.
        EvidenceType::COLLABORATION => ['document_edits', 'files_uploaded'],
    ];

    /** The types judged against the group average, which therefore get that average. */
    private const RELATIVE = [EvidenceType::COMMUNICATION, EvidenceType::COLLABORATION];

    /** @return list<string> the fact names a type may use; empty for none */
    public static function permitted(?string $type): array
    {
        return self::PERMITTED[EvidenceType::normalise($type)] ?? [];
    }

    /**
     * The facts to send for one criterion: the permitted ones, and for a relative
     * type the group average of those same ones. Nothing else.
     *
     * @param  array<string, mixed>  $own  one student's entry from GroupFacts
     * @return array<string, mixed>
     */
    public static function payload(?string $type, array $own): array
    {
        $names = self::permitted($type);
        $facts = array_intersect_key($own, array_flip($names));

        if (in_array(EvidenceType::normalise($type), self::RELATIVE, true)) {
            $facts['group_average'] = array_intersect_key($own['group_average'] ?? [], array_flip($names));
        }

        return $facts;
    }

    /**
     * Every figure a rationale for this criterion may legitimately cite, as
     * canonical strings ("3", "2.5").
     *
     * @param  array<string, mixed>  $own
     * @return list<string>
     */
    public static function figures(?string $type, array $own): array
    {
        $figures = [];

        $facts = self::payload($type, $own);

        array_walk_recursive($facts, function ($value) use (&$figures) {
            if (is_int($value) || is_float($value)) {
                $figures[] = self::canonical($value);
            }
        });

        return array_values(array_unique($figures));
    }

    /** The numbers a piece of text mentions, as canonical strings. */
    public static function numbersIn(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $m);

        return array_values(array_unique(array_map(
            fn ($n) => self::canonical((float) str_replace(',', '.', $n)),
            $m[0],
        )));
    }

    public static function canonical(int|float $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }

    /** Plain labels for the fact names, for screens and reports. */
    public const LABELS = [
        'tasks_assigned' => 'Tasks assigned',
        'tasks_completed' => 'Completed',
        'tasks_completed_on_time' => 'Completed on time',
        'tasks_completed_late' => 'Completed late',
        'late_days' => 'Days late',
        'tasks_overdue' => 'Overdue now',
        'messages_sent' => 'Messages sent',
        'document_edits' => 'Document edits',
        'files_uploaded' => 'Files uploaded',
    ];

    /**
     * The records a criterion was judged on, as one plain line:
     * "Tasks: 5 given, 4 done" or "Messages: 10 sent (group average 5)".
     *
     * @param  array<string, mixed>  $own  one student's facts as stored on a suggestion
     */
    public static function describe(?string $type, array $own): string
    {
        $n = fn (string $k) => self::canonical((float) ($own[$k] ?? 0));
        $avg = fn (string $k) => self::canonical((float) (($own['group_average'] ?? [])[$k] ?? 0));

        switch (EvidenceType::normalise($type)) {
            case EvidenceType::TASK_COMPLETION:
                return "Tasks: {$n('tasks_assigned')} given, {$n('tasks_completed')} done";

            case EvidenceType::RELIABILITY:
                $line = "Tasks: {$n('tasks_assigned')} given, {$n('tasks_completed_on_time')} done on time";

                if ((int) ($own['tasks_completed_late'] ?? 0) > 0) {
                    $line .= ", {$n('tasks_completed_late')} done late (".self::dayList($own['late_days'] ?? []).')';
                }

                return $line.((int) ($own['tasks_overdue'] ?? 0) > 0 ? ", {$n('tasks_overdue')} overdue now" : '');

            case EvidenceType::COMMUNICATION:
                return "Messages: {$n('messages_sent')} sent (group average {$avg('messages_sent')})";

            case EvidenceType::COLLABORATION:
                return "Edits: {$n('document_edits')} (group average {$avg('document_edits')}); Uploads: {$n('files_uploaded')} (group average {$avg('files_uploaded')})";
        }

        return self::describeLegacy($type, $own);
    }

    /**
     * The wording used before the records line was simplified ("Messages sent 10 (group average 5)").
     * An approval made before then is read back with this, so it shows what it was approved with.
     *
     * @param  array<string, mixed>  $own
     */
    public static function describeLegacy(?string $type, array $own): string
    {
        $facts = self::payload($type, $own);
        $average = $facts['group_average'] ?? [];
        unset($facts['group_average']);

        $parts = [];

        foreach ($facts as $name => $value) {
            $label = self::LABELS[$name] ?? $name;
            $shown = is_array($value) ? ($value === [] ? 'none' : implode(', ', $value)) : self::canonical($value);

            $part = "{$label} {$shown}";

            if (isset($average[$name])) {
                $part .= ' (group average '.self::canonical($average[$name]).')';
            }

            $parts[] = $part;
        }

        return implode('; ', $parts);
    }

    /**
     * The one plain sentence for a criterion, built from the figures alone, in short common words.
     * It is the rule-based reason on the marking page and in the exports.
     *
     * @param  array<string, mixed>  $own  one student's facts as stored on a suggestion
     */
    public static function sentence(?string $type, array $own): ?string
    {
        $n = fn (string $k) => self::canonical((float) ($own[$k] ?? 0));
        $plural = fn (string $k, string $one, string $many) => (float) ($own[$k] ?? 0) === 1.0 ? $one : $many;
        $avg = $own['group_average'] ?? [];
        $assigned = (int) ($own['tasks_assigned'] ?? 0);

        switch (EvidenceType::normalise($type)) {
            case EvidenceType::TASK_COMPLETION:
                if ($assigned === 0) {
                    return null;
                }

                return "Finished {$n('tasks_completed')} of {$assigned} ".($assigned === 1 ? 'task' : 'tasks').' ('.round(((float) ($own['tasks_completed'] ?? 0)) / $assigned * 100).'%).';

            case EvidenceType::RELIABILITY:
                if ($assigned === 0) {
                    return null;
                }

                $onTime = (int) ($own['tasks_completed_on_time'] ?? 0);
                $late = (int) ($own['tasks_completed_late'] ?? 0);
                $overdue = (int) ($own['tasks_overdue'] ?? 0);

                $parts = ["{$onTime} of {$assigned} ".($assigned === 1 ? 'task' : 'tasks').' '.($onTime === 1 ? 'was' : 'were').' done on time'];

                if ($late > 0) {
                    $parts[] = "{$late} ".($late === 1 ? 'was' : 'were').' late ('.self::dayList($own['late_days'] ?? []).')';
                }

                if ($overdue > 0) {
                    $parts[] = "{$overdue} ".($overdue === 1 ? 'is' : 'are').' overdue now';
                }

                $last = array_pop($parts);
                $line = $parts === [] ? $last : implode(', ', $parts).' and '.$last;
                $percent = round($onTime / $assigned * 100).'%';

                return $line.' ('.(count($parts) > 0 ? "{$percent} on time" : $percent).').';

            case EvidenceType::COMMUNICATION:
                return "Sent {$n('messages_sent')} ".$plural('messages_sent', 'message', 'messages').". The group's average is ".self::canonical((float) ($avg['messages_sent'] ?? 0)).'.';

            case EvidenceType::COLLABORATION:
                $mine = (float) ($own['document_edits'] ?? 0) + (float) ($own['files_uploaded'] ?? 0);
                $theirs = (float) ($avg['document_edits'] ?? 0) + (float) ($avg['files_uploaded'] ?? 0);

                return 'Made '.self::canonical($mine).($mine === 1.0 ? ' edit or upload' : ' edits and uploads').". The group's average is ".self::canonical($theirs).'.';
        }

        return null;
    }

    /** "10 days", "1 day", "2 and 5 days", "2, 3 and 5 days". @param  list<int|float>  $days */
    private static function dayList(array $days): string
    {
        $days = array_values(array_map(fn ($d) => self::canonical((float) $d), $days));

        if ($days === []) {
            return '0 days';
        }

        $last = array_pop($days);
        $text = $days === [] ? $last : implode(', ', $days).' and '.$last;

        return $text.($text === '1' ? ' day' : ' days');
    }
    /** The plain-words rule for each type, as given to the model. */
    public static function rule(string $type): string
    {
        return match ($type) {
            EvidenceType::TASK_COMPLETION => 'use only tasks completed against tasks assigned',
            EvidenceType::RELIABILITY => 'use only the on-time, late and overdue figures for the assigned tasks, and name any overdue tasks in the reason',
            EvidenceType::COMMUNICATION => 'use only messages sent, never document edits or uploads',
            EvidenceType::COLLABORATION => 'use only document edits and file uploads, never messages',
            default => 'use no recorded facts',
        };
    }
}
