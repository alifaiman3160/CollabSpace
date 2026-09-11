<x-layouts.app title="Dashboard — CollabSpace"
               :heading="'Good day, '.Str::of(auth()->user()->name)->explode(' ')->take(2)->implode(' ')"
               :subtitle="$counts['overdue'] > 0
                   ? $counts['overdue'].' '.Str::plural('task', $counts['overdue']).' overdue across your groups.'
                   : 'Nothing overdue across your groups today.'">

    <x-slot:actions>
        <x-button variant="icon" aria-label="Notifications">
            <x-icon name="bell" />
        </x-button>
        <x-button :href="route('lecturer.groups.create')" icon="plus">New group</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- Stat row --}}
        <section class="flex flex-wrap gap-3.5" aria-label="Teaching totals">
            <x-stat-card size="sm" tone="periwinkle" icon="book" icon-tone="neutral"
                         label="Subjects" :value="$counts['subjects']"
                         :meta="$counts['subjects'] ? 'You teach these' : 'None yet'" />

            <x-stat-card size="sm" icon="folder" icon-tone="info"
                         label="Groups" :value="$counts['groups']"
                         :meta="$counts['subjects'] ? 'Across your subjects' : 'None yet'" />

            <x-stat-card size="sm" icon="graduation" icon-tone="info"
                         label="Students" :value="$counts['students']"
                         :meta="$counts['students'] ? 'Enrolled in your groups' : 'None assigned'" />

            <x-stat-card size="sm" icon="check" icon-tone="success"
                         label="Tasks completed" :value="$counts['completed']"
                         meta="Marked done" />

            <x-stat-card size="sm" icon="alert" icon-tone="danger"
                         label="Overdue tasks" :value="$counts['overdue']"
                         :meta="$counts['overdue'] ? 'Past their deadline' : 'All on schedule'" />
        </section>

        {{-- All groups overview --}}
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">All groups overview</h2>
                <x-button variant="secondary" :href="route('lecturer.groups')">Manage groups</x-button>
            </div>

            @if ($groups->isEmpty())
                <x-empty-state
                    icon="folder"
                    title="No groups yet"
                    body="Create a subject, then add groups to it. Students are assigned to a group once it exists."
                    cta="Create a subject"
                    :href="route('lecturer.subjects.create')" />
            @else
                <x-data-table :headings="['Group', 'Subject', 'Members', 'Task progress', '']">
                    @foreach ($groups as $group)
                        <tr class="hover:bg-surface-soft">
                            <td>
                                <a href="{{ route('lecturer.groups.show', $group) }}"
                                   class="text-nav font-bold hover:text-periwinkle-link">{{ $group->name }}</a>
                            </td>

                            <td>
                                <x-badge tone="info" :title="$group->subject->name">{{ $group->subject->code }}</x-badge>
                            </td>

                            <td class="whitespace-nowrap">
                                <span class="text-meta font-semibold {{ $group->members_count >= $group->group_size ? 'text-ink-400' : 'text-ink' }}">
                                    {{ $group->members_count }} / {{ $group->group_size }}
                                </span>
                            </td>

                            <td>
                                <x-task-progress :done="$group->tasks_done"
                                                 :total="$group->tasks_total"
                                                 :overdue="$group->tasks_overdue" />
                            </td>

                            <td>
                                <div class="flex items-center justify-end gap-2">
                                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                              :href="route('lecturer.reports.show', $group)">Report</x-button>

                                    {{-- Monitor is the read-only progress view; membership management lives
                                         on the Groups screen. --}}
                                    <x-button :href="route('lecturer.progress.show', $group)"
                                              class="!px-4 !py-2 !text-meta">Monitor</x-button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        </section>
    </div>

</x-layouts.app>
