<?php

namespace App\View\Composers;

use Illuminate\View\View;

/**
 * The shell is identical for every role — only the nav array changes.
 * See DESIGN-NOTES.md ("Component build order").
 */
class NavigationComposer
{
    public function compose(View $view): void
    {
        // Always the signed-in user's own role. It used to be overridable by
        // passing role="..." to <x-layouts.app>, which was both redundant and a
        // trap: Blade unsets any variable whose name collides with a component
        // attribute, so passing it actually *deleted* $role inside the layout
        // and the sidebar fell back to showing "student" for everyone.
        $role = auth()->user()->role ?? 'student';

        $view->with([
            'role' => $role,
            'roleLabel' => match ($role) {
                'admin' => 'Administrator Portal',
                'lecturer' => 'Lecturer Portal',
                default => 'Student Portal',
            },
            'nav' => $this->navFor($role),
        ]);
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
}
