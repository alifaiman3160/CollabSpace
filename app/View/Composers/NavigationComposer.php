<?php

namespace App\View\Composers;

use App\Models\Group;
use App\Models\Rubric;
use App\Models\User;
use App\Support\SidebarState;
use App\Support\SubjectPalette;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The shell is identical for every role — only the nav array changes.
 * See DESIGN-NOTES.md ("Component build order").
 */
class NavigationComposer
{
    /** Most groups listed under an expandable item (the rest are one click away on its own page). */
    public const MAX_GROUPS = 6;

    /** Items that open into the lecturer's groups, with the key their open state is remembered under. */
    protected const LECTURER_GROUP_ITEMS = [
        'lecturer.marking' => 'marking',
        'lecturer.reports' => 'reports',
        'lecturer.progress' => 'progress',
    ];

    /** Items that open into the student's groups, but only with two or more groups. */
    protected const STUDENT_GROUP_ITEMS = [
        'student.workspace' => ['key' => 'workspace', 'route' => 'student.workspace.show'],
        'student.board' => ['key' => 'board', 'route' => 'student.board.show'],
        'student.documents' => ['key' => 'documents', 'route' => 'student.documents.group'],
        'student.chat' => ['key' => 'chat', 'route' => 'student.chat.show'],
    ];

    public function compose(View $view): void
    {
        // Always the signed-in user's own role. It used to be overridable by
        // passing role="..." to <x-layouts.app>, which was both redundant and a
        // trap: Blade unsets any variable whose name collides with a component
        // attribute, so passing it actually *deleted* $role inside the layout
        // and the sidebar fell back to showing "student" for everyone.
        $user = auth()->user();
        $role = $user->role ?? 'student';

        $collapsed = SidebarState::collapsed(request());
        $nav = $user ? $this->withGroups($this->navFor($role), $user, $role) : $this->navFor($role);
        $current = $this->currentSection($nav);

        $view->with([
            'role' => $role,
            'roleLabel' => match ($role) {
                'admin' => 'Administrator Portal',
                'lecturer' => 'Lecturer Portal',
                default => 'Student Portal',
            },
            'nav' => $nav,
            'sidebar' => [
                'collapsed' => $collapsed === true,
                // Nobody has chosen yet: the page script may start the rail collapsed on a narrow screen.
                'auto' => $collapsed === null,
                // One section at a time: the one that holds this page. The script keeps it so on every navigation.
                'current' => $current,
                'open' => $current !== null ? [$current] : [],
            ],
        ]);
    }

    /**
     * The key of the item that opens and holds the current page (its own page, or one of its groups), or null when the
     * page belongs to none (Dashboard, Notifications, My account, Groups, Students ...). The same test as x-nav-item's.
     *
     * @param  array<int, array<string, mixed>>  $nav
     */
    protected function currentSection(array $nav): ?string
    {
        foreach ($nav as $item) {
            $children = $item['children'] ?? [];

            if (empty($item['group']) || count($children) === 0) {
                continue;
            }

            $href = \Illuminate\Support\Facades\Route::has($item['route']) ? route($item['route']) : (string) $item['route'];
            $path = trim((string) parse_url($href, PHP_URL_PATH), '/');

            if (($path !== '' && request()->is($path, $path.'/*')) || SidebarState::activeChild(request()->path(), $children) !== null) {
                return $item['group'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{icon: string, label: string, route: string, live?: bool}>
     */
    protected function navFor(string $role): array
    {
        return match ($role) {
            'admin' => [
                ['icon' => 'grid',       'label' => 'Dashboard',       'route' => 'admin.dashboard'],
                ['icon' => 'users',      'label' => 'User accounts',   'route' => 'admin.users'],
                ['icon' => 'book',       'label' => 'Subjects',        'route' => 'admin.subjects'],
                ['icon' => 'bell',       'label' => 'Notifications',   'route' => 'admin.notifications', 'live' => true],
                ['icon' => 'user',       'label' => 'My account',      'route' => 'account'],
            ],
            'lecturer' => [
                ['icon' => 'grid',       'label' => 'Dashboard',       'route' => 'lecturer.dashboard'],
                ['icon' => 'book',       'label' => 'My subjects',     'route' => 'lecturer.subjects'],
                ['icon' => 'folder',     'label' => 'Groups',          'route' => 'lecturer.groups'],
                ['icon' => 'check',      'label' => 'Marking',         'route' => 'lecturer.marking'],
                ['icon' => 'chart',      'label' => 'Contribution',    'route' => 'lecturer.reports'],
                ['icon' => 'graduation', 'label' => 'Students',        'route' => 'lecturer.students'],
                ['icon' => 'clock',      'label' => 'Monitor progress','route' => 'lecturer.progress'],
                ['icon' => 'bell',       'label' => 'Notifications',   'route' => 'lecturer.notifications', 'live' => true],
                ['icon' => 'user',       'label' => 'My account',      'route' => 'account'],
            ],
            default => [
                ['icon' => 'grid',  'label' => 'Dashboard',     'route' => 'student.dashboard'],
                ['icon' => 'users', 'label' => 'My workspace',  'route' => 'student.workspace'],
                ['icon' => 'folder','label' => 'Task board',    'route' => 'student.board'],
                ['icon' => 'file',  'label' => 'Documents',     'route' => 'student.documents'],
                ['icon' => 'chat',  'label' => 'Group chat',    'route' => 'student.chat'],
                ['icon' => 'bell',       'label' => 'Notifications', 'route' => 'student.notifications', 'live' => true],
                ['icon' => 'user',  'label' => 'My account',    'route' => 'account'],
            ],
        };
    }

    /**
     * Adds the group shortcuts to the items that have them. The person's own groups are read once for the whole
     * sidebar (a fixed handful of queries however many items open), never one query per item.
     *
     * @param  array<int, array<string, mixed>>  $nav
     * @return array<int, array<string, mixed>>
     */
    protected function withGroups(array $nav, User $user, string $role): array
    {
        if ($role === 'lecturer') {
            $groups = $this->lecturerGroups($user);

            // Always the same two queries (the groups, and the current rubric of each subject for the marking links).
            // A subject with several assessments has a current rubric for each: the marking links use the active one.
            $assessments = \App\Models\Assessment::query()->whereIn('subject_id', $user->subjects()->select('id'))
                ->orderBy('position')->orderBy('id')->get()->groupBy('subject_id');

            $rubrics = Rubric::query()
                ->whereIn('subject_id', $user->subjects()->select('id'))
                ->where('is_current', true)
                ->get(['id', 'subject_id', 'assessment_id'])
                ->groupBy('subject_id')
                ->map(function ($rows, $subjectId) use ($assessments) {
                    $active = \App\Models\Assessment::pickActive($assessments->get($subjectId, collect()))?->id;

                    return ($rows->firstWhere('assessment_id', $active) ?? $rows->first())->id;
                });

            if ($groups->isEmpty()) {
                return $nav;
            }

            return array_map(function (array $item) use ($groups, $rubrics) {
                $key = self::LECTURER_GROUP_ITEMS[$item['route']] ?? null;

                if ($key === null) {
                    return $item;
                }

                $children = $groups->map(function (Group $group) use ($key, $rubrics) {
                    $rubric = $rubrics[$group->subject_id] ?? null;

                    [$route, $params] = match (true) {
                        $key === 'reports' => ['lecturer.reports.show', [$group]],
                        $key === 'progress' => ['lecturer.progress.show', [$group]],
                        $rubric !== null => ['lecturer.marking.group', [$rubric, $group]],
                        default => ['lecturer.marking', []],
                    };

                    return [
                        'label' => $group->name,
                        'suffix' => null,
                        'href' => route($route, $params),
                        'match' => $params === [] ? null : route($route, $params, false),
                        'dot' => SubjectPalette::for($group->subject_id)['accent'],
                    ];
                })->all();

                $children[] = ['label' => 'All groups', 'suffix' => null, 'href' => route($item['route']), 'match' => null, 'dot' => null, 'all' => true];

                return $item + ['group' => $key, 'children' => $children];
            }, $nav);
        }

        if ($role === 'student') {
            $groups = $user->groups()->with('subject:id,code')->orderBy('groups.name')->get();

            if ($groups->count() < 2) {
                return $nav;
            }

            return array_map(function (array $item) use ($groups) {
                $spec = self::STUDENT_GROUP_ITEMS[$item['route']] ?? null;

                if ($spec === null) {
                    return $item;
                }

                $children = $groups->take(self::MAX_GROUPS)->map(fn (Group $group) => [
                    'label' => $group->name,
                    'suffix' => $group->subject?->code,
                    'href' => route($spec['route'], $group),
                    'match' => route($spec['route'], $group, false),
                    'dot' => SubjectPalette::for($group->subject_id)['accent'],
                ])->all();

                return $item + ['group' => $spec['key'], 'children' => $children];
            }, $nav);
        }

        return $nav;
    }

    /** The lecturer's own groups, the ones with the most overdue tasks first, cut to what the sidebar shows. */
    protected function lecturerGroups(User $user): Collection
    {
        return Group::query()
            ->whereIn('subject_id', $user->subjects()->select('id'))
            ->withCount(['tasks as overdue_count' => fn ($query) => $query->overdue()])
            ->orderByDesc('overdue_count')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MAX_GROUPS)
            ->get();
    }
}
