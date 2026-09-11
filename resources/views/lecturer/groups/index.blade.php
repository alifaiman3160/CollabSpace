<x-layouts.app title="Groups — CollabSpace"
               heading="Groups"
               :subtitle="$groups->count().' '.Str::plural('group', $groups->count()).
                   ($subjectId ? ' in '.$subjects->firstWhere('id', $subjectId)->code : ' across your subjects').'.'">

    <x-slot:actions>
        @if ($subjects->isNotEmpty())
            <x-button :href="route('lecturer.groups.create', $subjectId ? ['subject' => $subjectId] : [])" icon="plus">
                New group
            </x-button>
        @endif
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        @if ($subjects->isEmpty())
            <x-empty-state
                icon="book"
                title="No subjects yet"
                body="Groups live inside a subject. Create a subject first."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            {{-- Subject filter, as the design's segmented control --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap rounded-pill bg-surface-muted p-[3px]">
                    <a href="{{ route('lecturer.groups') }}"
                       class="flex h-8 items-center rounded-pill px-[15px] text-meta font-bold transition-colors
                              {{ $subjectId === null ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                        All subjects
                    </a>
                    @foreach ($subjects as $subject)
                        <a href="{{ route('lecturer.groups', ['subject' => $subject->id]) }}"
                           title="{{ $subject->name }}"
                           class="flex h-8 items-center rounded-pill px-[15px] text-meta font-bold transition-colors
                                  {{ $subjectId === $subject->id ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                            {{ $subject->code }}
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($groups->isEmpty())
                <x-empty-state
                    icon="folder"
                    title="No groups here yet"
                    body="Create a group, then assign students to it from the group's own screen."
                    cta="Create a group"
                    :href="route('lecturer.groups.create', $subjectId ? ['subject' => $subjectId] : [])" />
            @else
                <x-data-table :headings="['Group', 'Subject', 'Members', 'Task progress', '']">
                    @foreach ($groups as $group)
                        @php
                            // Built here, then passed through Js::from below. Blade compiles
                            // {{ }} echoes inside a component attribute but NOT @ directives,
                            // so @js() there would render literally and break the payload.
                            $deleteBody = "Deleting {$group->name} removes the group and everything "
                                ."recorded in it. This cannot be undone.";
                            $consequences = array_values(array_filter([
                                $group->members_count > 0
                                    ? $group->members_count.' '.Str::plural('membership', $group->members_count).' (the students keep their accounts)'
                                    : null,
                                $group->tasks_total > 0
                                    ? $group->tasks_total.' '.Str::plural('task', $group->tasks_total)
                                    : null,
                                $group->documents_count > 0
                                    ? $group->documents_count.' '.Str::plural('document', $group->documents_count).' and every saved version'
                                    : null,
                                $group->messages_count > 0
                                    ? $group->messages_count.' chat '.Str::plural('message', $group->messages_count)
                                    : null,
                                'every contribution record for this group',
                            ]));
                        @endphp
                        <tr class="hover:bg-surface-soft">
                            <td>
                                <a href="{{ route('lecturer.groups.show', $group) }}"
                                   class="text-nav font-bold hover:text-periwinkle-link">{{ $group->name }}</a>
                            </td>

                            <td>
                                <x-badge tone="info" :title="$group->subject->name">{{ $group->subject->code }}</x-badge>
                            </td>

                            <td class="whitespace-nowrap">
                                <span class="text-meta font-semibold">
                                    {{ $group->members_count }} / {{ $group->group_size }}
                                </span>
                                @if ($group->members_count >= $group->group_size)
                                    <span class="ml-1.5"><x-badge tone="neutral">Full</x-badge></span>
                                @endif
                            </td>

                            <td>
                                <x-task-progress :done="$group->tasks_done"
                                                 :total="$group->tasks_total"
                                                 :overdue="$group->tasks_overdue" />
                            </td>

                            <td>
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-button :href="route('lecturer.groups.show', $group)"
                                              class="!px-4 !py-2 !text-meta">Manage</x-button>

                                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta !text-danger-text"
                                        x-on:click="confirm = {
                                            url: '{{ route('lecturer.groups.destroy', $group) }}',
                                            method: 'DELETE',
                                            title: 'Delete this group?',
                                            body: {{ Js::from($deleteBody) }},
                                            consequences: {{ Js::from($consequences) }},
                                            action: 'Delete group',
                                            tone: 'danger',
                                        }">Delete</x-button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
