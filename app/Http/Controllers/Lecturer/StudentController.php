<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\ActivityLog;
use App\Models\GroupMember;
use App\Support\ContributionReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudentController extends LecturerController
{
    public function __construct(protected ContributionReport $reports) {}

    /**
     * Every student across the lecturer's own subjects, with the group they
     * sit in and how they are standing in it.
     *
     * A student in two of this lecturer's subjects appears once per group,
     * because their standing is per group — one row per membership is the
     * honest shape.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $groups = $this->groups()->with('subject')->get()->keyBy('id');

        if ($groups->isEmpty()) {
            return view('lecturer.students', [
                'rows' => collect(),
                'search' => $search,
                'hasGroups' => false,
            ]);
        }

        // Threshold per group, from the same report the lecturer can open.
        $standings = $groups->mapWithKeys(function ($group) {
            $report = $this->reports->for($group);

            return [$group->id => [
                'threshold' => $report['threshold'],
                'average' => $report['average'],
                'scores' => $report['members']->mapWithKeys(
                    fn ($m) => [$m['user']->id => ['score' => $m['score'], 'share' => $m['share'], 'flagged' => $m['flagged']]]
                ),
            ]];
        });

        $memberships = GroupMember::query()
            ->whereIn('group_id', $groups->keys())
            ->with('user')
            ->get()
            ->filter(fn (GroupMember $m) => $m->user !== null);

        // Last activity per member per group, in one query rather than one per
        // row in the view.
        $lastActive = ActivityLog::query()
            ->whereIn('group_id', $groups->keys())
            ->selectRaw('user_id, group_id, MAX(created_at) AS last_at')
            ->groupBy('user_id', 'group_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->user_id.':'.$row->group_id => $row->last_at]);

        $rows = $memberships
            ->map(function (GroupMember $membership) use ($groups, $standings, $lastActive) {
                $group = $groups[$membership->group_id];
                $standing = $standings[$group->id];
                $mine = $standing['scores'][$membership->user_id] ?? ['score' => 0, 'share' => 0.0, 'flagged' => false];

                return [
                    'user' => $membership->user,
                    'group' => $group,
                    'joined_at' => $membership->joined_at,
                    'score' => $mine['score'],
                    'share' => $mine['share'],
                    'flagged' => $mine['flagged'],
                    'threshold' => $standing['threshold'],
                    'last_at' => $lastActive[$membership->user_id.':'.$membership->group_id] ?? null,
                ];
            })
            ->when($search !== '', fn ($rows) => $rows->filter(function (array $row) use ($search) {
                $needle = mb_strtolower($search);

                return str_contains(mb_strtolower($row['user']->name), $needle)
                    || str_contains(mb_strtolower((string) $row['user']->email), $needle)
                    || str_contains(mb_strtolower((string) $row['user']->matric_no), $needle)
                    || str_contains(mb_strtolower($row['group']->name), $needle)
                    || str_contains(mb_strtolower($row['group']->subject->code), $needle);
            }))
            ->sortBy([
                fn ($a, $b) => $a['group']->subject->code <=> $b['group']->subject->code,
                fn ($a, $b) => $a['user']->name <=> $b['user']->name,
            ])
            ->values();

        return view('lecturer.students', [
            'rows' => $rows,
            'search' => $search,
            'hasGroups' => true,
            'flaggedCount' => $rows->where('flagged', true)->count(),
            'studentCount' => $rows->pluck('user.id')->unique()->count(),
        ]);
    }

}
