<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds a group's contribution report.
 *
 * Everything here reads activity_logs and nothing else. The log is the record
 * of what happened, and each row carries the weight that applied when it was
 * written — so re-tuning config/contribution.php changes future scoring without
 * silently rewriting past reports. Counting tasks or messages out of their own
 * tables instead would produce a second, divergent definition of the same
 * number, which is exactly what a marking dispute does not need.
 *
 * The one thing read from config at report time is the low-participation ratio,
 * which is a judgement about the current cohort rather than a property of a
 * past action.
 */
class ContributionReport
{
    /**
     * The metric columns, in the order the report displays them.
     *
     * 'documents' deliberately covers both document_created and
     * document_edited: the design has a single "Doc edits" column, and folding
     * creations in keeps the columns a complete account of the score rather
     * than one that quietly omits a scoring activity.
     *
     * @var array<string, array{label: string, types: list<string>}>
     */
    public const METRICS = [
        'tasks_done' => ['label' => 'Tasks done', 'types' => ['task_completed']],
        'tasks_created' => ['label' => 'Created', 'types' => ['task_created']],
        'messages' => ['label' => 'Messages', 'types' => ['message_sent']],
        'files' => ['label' => 'Files', 'types' => ['file_uploaded']],
        'documents' => ['label' => 'Doc edits', 'types' => ['document_created', 'document_edited']],
    ];

    /**
     * @return array{
     *     group: Group,
     *     members: Collection,
     *     total: int,
     *     average: float,
     *     ratio: float,
     *     threshold: float,
     *     hasActivity: bool,
     *     flagged: Collection,
     *     top: array|null,
     *     weights: array<string, int>,
     *     generatedAt: \Illuminate\Support\Carbon,
     * }
     */
    public function for(Group $group, ?Assessment $assessment = null): array
    {
        // One pass over the log, grouped by member and activity type. For one assessment of a subject that has several,
        // only the entries inside its dates (a read-side filter; the log itself is never touched).
        $aggregate = Assessment::limit(ActivityLog::query(), $assessment)
            ->where('group_id', $group->getKey())
            ->selectRaw('user_id, activity_type, COUNT(*) AS entries, SUM(weight) AS points')
            ->groupBy('user_id', 'activity_type')
            ->get()
            ->groupBy('user_id');

        return $this->assemble($group, $aggregate);
    }

    /**
     * The same report for several groups, from ONE read of the log instead of one each. Every number comes from the
     * same code as for(); only the query is shared. Keyed by group id.
     *
     * @param  Collection<int, Group>  $groups
     * @return Collection<int, array>
     */
    public function forMany(Collection $groups, ?Assessment $assessment = null): Collection
    {
        $log = Assessment::limit(ActivityLog::query(), $assessment)
            ->whereIn('group_id', $groups->modelKeys())
            ->selectRaw('group_id, user_id, activity_type, COUNT(*) AS entries, SUM(weight) AS points')
            ->groupBy('group_id', 'user_id', 'activity_type')
            ->get()
            ->groupBy('group_id');

        return $groups->mapWithKeys(fn (Group $group) => [
            $group->getKey() => $this->assemble($group, $log->get($group->getKey(), collect())->groupBy('user_id')),
        ]);
    }

    /** @param  Collection  $aggregate  the group's log rows, grouped by user id */
    protected function assemble(Group $group, Collection $aggregate): array
    {
        $group->loadMissing(['subject.lecturer', 'members']);

        $members = $group->members->sortBy('name')->values();

        $rows = $members->map(function (User $member) use ($aggregate) {
            $mine = $aggregate->get($member->getKey(), collect());

            $counts = [];
            foreach (self::METRICS as $key => $metric) {
                $counts[$key] = (int) $mine
                    ->whereIn('activity_type', $metric['types'])
                    ->sum('entries');
            }

            return [
                'user' => $member,
                // A member with no rows at all still gets a row here, scoring 0.
                'score' => (int) $mine->sum('points'),
                'counts' => $counts,
                'share' => 0.0,
                'flagged' => false,
            ];
        });

        // The total is the sum over current members. Logs belonging to someone
        // since removed from the group are left out of both, so the shares add
        // up to the group as it stands today.
        $total = (int) $rows->sum('score');
        // Cast throughout: PHP's / returns an int when the division is exact,
        // so these would otherwise be int here and float there.
        $average = $members->isNotEmpty() ? (float) ($total / $members->count()) : 0.0;

        $ratio = (float) config('contribution.low_participation_ratio');
        $threshold = (float) ($average * $ratio);

        $rows = $rows->map(function (array $row) use ($total, $threshold) {
            $row['share'] = $total > 0 ? (float) ($row['score'] / $total * 100) : 0.0;

            // With no activity anywhere the threshold is 0 and nobody is
            // flagged — a silent group is not four free riders, it is a group
            // that has not started.
            $row['flagged'] = $threshold > 0 && $row['score'] < $threshold;

            return $row;
        })->sortByDesc('score')->values();

        $top = $rows->first();

        return [
            'group' => $group,
            'members' => $rows,
            'total' => $total,
            'average' => $average,
            'ratio' => $ratio,
            'threshold' => $threshold,
            'hasActivity' => $total > 0,
            'flagged' => $rows->where('flagged', true)->values(),
            'top' => $top && $top['score'] > 0 ? $top : null,
            // Zero-weight rows (the deletion trail) are not a scoring rule, so they are not listed with the weights.
            'weights' => array_filter(config('contribution.weights'), fn ($weight) => (int) $weight > 0),
            'generatedAt' => now(),
        ];
    }

    /**
     * Rows for the CSV export: the same figures the screen shows, plus the
     * provenance a marking decision needs to be defensible later.
     *
     * @return list<list<string|int|float>>
     */
    public function toCsvRows(array $report, ?Assessment $assessment = null): array
    {
        $group = $report['group'];
        $subject = $group->subject;

        $rows = [
            ['CollabSpace contribution report'],
            [],
            ['Group', $group->name],
            ['Subject', $subject->code.' - '.$subject->name],
            // Which assessment's dates the figures are for, only where the subject has several.
            ...($assessment !== null && $subject->assessments->count() > 1 ? [['Assessment', $assessment->title]] : []),
            ['Lecturer', $subject->lecturer?->name ?? 'Unknown'],
            ['Generated', $report['generatedAt']->format('Y-m-d H:i:s')],
            ['Members', $report['members']->count()],
            ['Group total', $report['total']],
            ['Group average', round($report['average'], 2)],
            ['Low participation ratio', $report['ratio']],
            ['Low participation threshold', round($report['threshold'], 2)],
            [],
            ['Weights used'],
        ];

        foreach ($report['weights'] as $type => $weight) {
            $rows[] = [$type, $weight];
        }

        $rows[] = [];

        $header = ['Member', 'Matric no.'];
        foreach (self::METRICS as $metric) {
            $header[] = $metric['label'];
        }
        $header[] = 'Score';
        $header[] = 'Share %';
        $header[] = 'Low participation';
        $rows[] = $header;

        foreach ($report['members'] as $member) {
            $line = [$member['user']->name, $member['user']->matric_no ?? ''];

            foreach (array_keys(self::METRICS) as $key) {
                $line[] = $member['counts'][$key];
            }

            $line[] = $member['score'];
            $line[] = round($member['share'], 1);
            $line[] = $member['flagged'] ? 'YES' : 'no';

            $rows[] = $line;
        }

        return $rows;
    }
}
