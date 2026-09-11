<x-layouts.app title="Monitor progress — CollabSpace"
               heading="Monitor progress"
               subtitle="Pick a group to see its board, members and recent activity.">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        @if ($groups->isEmpty())
            <x-empty-state
                icon="chart"
                title="No groups to monitor"
                body="Create a subject and a group, then assign students to it."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            <x-data-table :headings="['Group', 'Subject', 'Members', 'Task progress', '']">
                @foreach ($groups as $group)
                    <tr class="hover:bg-surface-soft">
                        <td>
                            <a href="{{ route('lecturer.progress.show', $group) }}"
                               class="text-nav font-bold hover:text-periwinkle-link">{{ $group->name }}</a>
                        </td>
                        <td>
                            <x-badge tone="info" :title="$group->subject->name">{{ $group->subject->code }}</x-badge>
                        </td>
                        <td class="whitespace-nowrap text-meta font-semibold">
                            {{ $group->members_count }} / {{ $group->group_size }}
                        </td>
                        <td>
                            <x-task-progress :done="$group->tasks_done"
                                             :total="$group->tasks_total"
                                             :overdue="$group->tasks_overdue" />
                        </td>
                        <td>
                            <div class="flex justify-end">
                                <x-button :href="route('lecturer.progress.show', $group)"
                                          class="!px-4 !py-2 !text-meta">Monitor</x-button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif
    </div>

</x-layouts.app>
