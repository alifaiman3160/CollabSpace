<x-layouts.app title="My subjects — CollabSpace"
               heading="My subjects"
               :subtitle="$subjects->count().' '.Str::plural('subject', $subjects->count()).' you teach.'">

    <x-slot:actions>
        <x-button :href="route('lecturer.subjects.create')" icon="plus">New subject</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        @if ($subjects->isEmpty())
            <x-empty-state
                icon="book"
                title="No subjects yet"
                body="A subject holds the groups your students work in. Create one to get started."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            <x-data-table :headings="['Code', 'Subject', 'Groups', 'Students', 'Default group size', '']">
                @foreach ($subjects as $subject)
                    @php
                        // Built here, then passed through Js::from below. Blade compiles
                        // {{ }} echoes inside a component attribute but NOT @ directives,
                        // so @js() there would render literally and break the payload.
                        $deleteBody = "Deleting {$subject->code} removes the subject and everything "
                            ."recorded under it. This cannot be undone.";
                        $consequences = $subject->groups_count > 0
                            ? [
                                $subject->groups_count.' '.Str::plural('group', $subject->groups_count),
                                $subject->group_members_count.' '.Str::plural('membership', $subject->group_members_count),
                                'every task, document, chat message and contribution record in those groups',
                            ]
                            : [];
                    @endphp
                    <tr class="hover:bg-surface-soft">
                        <td>
                            <x-badge tone="info">{{ $subject->code }}</x-badge>
                        </td>

                        <td>
                            <a href="{{ route('lecturer.subjects.edit', $subject) }}"
                               class="text-nav font-bold hover:text-periwinkle-link">{{ $subject->name }}</a>
                        </td>

                        <td>
                            @if ($subject->groups_count > 0)
                                <a href="{{ route('lecturer.groups', ['subject' => $subject->id]) }}"
                                   class="text-meta font-semibold text-periwinkle-link hover:underline">
                                    {{ $subject->groups_count }}
                                </a>
                            @else
                                <span class="text-meta font-semibold text-ink-300">0</span>
                            @endif
                        </td>

                        <td class="text-meta font-semibold text-ink-400">{{ $subject->group_members_count }}</td>

                        <td class="text-meta font-semibold text-ink-400">{{ $subject->group_size }}</td>

                        <td>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                          :href="route('lecturer.groups', ['subject' => $subject->id])">Groups</x-button>

                                <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                          :href="route('lecturer.subjects.edit', $subject)">Edit</x-button>

                                <x-button variant="secondary" class="!px-4 !py-2 !text-meta !text-danger-text"
                                    x-on:click="confirm = {
                                        url: '{{ route('lecturer.subjects.destroy', $subject) }}',
                                        method: 'DELETE',
                                        title: 'Delete this subject?',
                                        body: {{ Js::from($deleteBody) }},
                                        consequences: {{ Js::from($consequences) }},
                                        action: 'Delete subject',
                                        tone: 'danger',
                                    }">Delete</x-button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
