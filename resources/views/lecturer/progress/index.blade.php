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
                    <tr>
                        <x-table.group-cells :group="$group" :href="route('lecturer.progress.show', $group)" />
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
