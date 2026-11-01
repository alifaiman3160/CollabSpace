<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\ActivityLog;
use App\Models\GroupMember;
use App\Models\User;
use App\Support\SubjectPalette;
use App\Support\ContributionReport;
use App\Support\Sections\SubjectSectionFilter;
use Illuminate\Http\JsonResponse;
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

        // A group or a subject picked from the search suggestions; ids outside the lecturer's own simply match nothing.
        $groupFilter = (int) $request->query('group', 0);
        $subjectFilter = (int) $request->query('subject', 0);

        $groups = $this->groups()->with(['subject', 'section'])->get()->keyBy('id');
        $filter = SubjectSectionFilter::for($request, $this->lecturer());

        if ($groups->isEmpty()) {
            return view('lecturer.students', [
                'filter' => $filter,
                'rows' => collect(),
                'search' => $search,
                'filterGroup' => null,
                'filterSubject' => null,
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
            ->when($groupFilter > 0, fn ($rows) => $rows->filter(fn (array $row) => $row['group']->id === $groupFilter))
            ->when($subjectFilter > 0, fn ($rows) => $rows->filter(fn (array $row) => $row['group']->subject_id === $subjectFilter))
            ->when($filter['sectionId'], fn ($rows, $id) => $rows->filter(fn (array $row) => $row['group']->section_id === $id))
            ->when($search !== '', fn ($rows) => $rows->filter(function (array $row) use ($search) {
                $needle = mb_strtolower($search);

                return str_contains(mb_strtolower($row['user']->name), $needle)
                    || str_contains(mb_strtolower((string) $row['user']->email), $needle)
                    || str_contains(mb_strtolower((string) $row['user']->matric_no), $needle)
                    || str_contains(mb_strtolower($row['group']->name), $needle)
                    || str_contains(mb_strtolower($row['group']->subject->code), $needle)
                    || str_contains(mb_strtolower($row['group']->subject->name), $needle)
                    || str_contains(mb_strtolower((string) $row['group']->section?->name), $needle);
            }))
            ->sortBy([
                fn ($a, $b) => $a['group']->subject->code <=> $b['group']->subject->code,
                fn ($a, $b) => $a['user']->name <=> $b['user']->name,
            ])
            ->values();

        return view('lecturer.students', [
            'filter' => $filter,
            'rows' => $rows,
            'search' => $search,
            'filterGroup' => $groupFilter > 0 ? $groups->get($groupFilter) : null,
            'filterSubject' => $subjectFilter > 0 ? $groups->pluck('subject')->unique('id')->firstWhere('id', $subjectFilter) : null,
            'hasGroups' => true,
            'flaggedCount' => $rows->where('flagged', true)->count(),
            'studentCount' => $rows->pluck('user.id')->unique()->count(),
        ]);
    }

    /** The most suggestions one request returns. */
    public const SUGGESTIONS = 8;

    /**
     * What the search box offers after one typed character: groups and subjects that match (each labelled as such, to
     * filter the table by), then students, the names that start with the text before those that merely contain it.
     * Only people in groups of this lecturer's own subjects. One row per membership, like the table.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['results' => []]);
        }

        $q = mb_substr($q, 0, 60);
        $groups = $this->groups()->with('subject:id,code,name')->get()->keyBy('id');

        if ($groups->isEmpty()) {
            return response()->json(['results' => []]);
        }

        $needle = mb_strtolower($q);
        $results = [];

        $subjects = $groups->pluck('subject')->unique('id')->sortBy('code');

        foreach ($subjects as $subject) {
            if (str_contains(mb_strtolower($subject->code), $needle) || str_contains(mb_strtolower($subject->name), $needle)) {
                $results[] = ['type' => 'subject', 'label' => $subject->code.' - '.$subject->name] + $this->subjectPart($subject) + ['subject_id' => $subject->id];
            }
        }

        foreach ($groups->sortBy('name') as $group) {
            if (str_contains(mb_strtolower($group->name), $needle)) {
                $results[] = ['type' => 'group', 'label' => $group->name, 'group_id' => $group->id, 'group' => $group->name] + $this->subjectPart($group->subject);
            }
        }

        $results = array_slice($results, 0, 3);   // never let labels crowd out the students

        // Escape the characters LIKE treats as wildcards; "!" is the escape character, which every database accepts.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';
        $starts = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';

        $students = User::query()
            ->join('group_members', 'group_members.user_id', '=', 'users.id')
            ->whereIn('group_members.group_id', $groups->keys())
            ->where(function ($query) use ($like) {
                $query->whereRaw("LOWER(users.name) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(users.matric_no, '')) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(users.email) LIKE ? ESCAPE '!'", [$like]);
            })
            ->orderByRaw("CASE WHEN LOWER(users.name) LIKE ? ESCAPE '!' OR LOWER(COALESCE(users.matric_no, '')) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$starts, $starts])
            ->orderBy('users.name')
            ->orderBy('group_members.group_id')
            ->limit(self::SUGGESTIONS - count($results))
            ->get(['users.id', 'users.name', 'users.matric_no', 'users.email', 'group_members.group_id']);

        foreach ($students as $student) {
            $group = $groups[$student->group_id];

            $results[] = [
                'type' => 'student',
                'label' => $student->name,
                'student_id' => $student->id,
                'name' => $student->name,
                'matric' => $student->matric_no ?? $student->email,
                'group_id' => $group->id,
                'group' => $group->name,
            ] + $this->subjectPart($group->subject);
        }

        return response()->json(['results' => $results]);
    }

    /** @return array{subject_id: int, subject_code: string, subject_name: string, accent: string, palette: string} */
    protected function subjectPart($subject): array
    {
        $colour = SubjectPalette::for($subject->id);

        return ['subject_id' => $subject->id, 'subject_code' => $subject->code, 'subject_name' => $subject->name, 'accent' => $colour['accent'], 'palette' => $colour['name']];
    }
}
