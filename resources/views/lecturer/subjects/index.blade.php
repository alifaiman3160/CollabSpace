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
            <x-data-table :headings="['Subject', ['Groups', 'right'], ['Students', 'right'], ['Default size', 'right'], 'Project deadline', '']">
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
                    <tr>
                        <td>
                            <x-table.stack :href="route('lecturer.subjects.edit', $subject)">
                                <x-slot:primary>{{ $subject->name }}</x-slot:primary>
                                <x-badge tone="info">{{ $subject->code }}</x-badge>
                            </x-table.stack>
                        </td>

                        <td class="num">
                            @if ($subject->groups_count > 0)
                                <a href="{{ route('lecturer.groups', ['subject' => $subject->id]) }}"
                                   class="interactive rounded-md text-meta font-semibold text-periwinkle-link hover:underline">
                                    {{ $subject->groups_count }}
                                </a>
                            @else
                                <span class="text-meta font-semibold text-ink-300">0</span>
                            @endif
                        </td>

                        <td class="num text-meta font-semibold text-ink-400">{{ $subject->group_members_count }}</td>

                        <td class="num text-meta font-semibold text-ink-400">{{ $subject->group_size }}</td>

                        <td>
                            <x-table.date :date="$subject->project_deadline" />
                        </td>

                        <td>
                            <x-table.row-actions>
                                <x-table.quiet-action tone="neutral" :href="route('lecturer.groups', ['subject' => $subject->id])">Groups</x-table.quiet-action>

                                <x-table.quiet-action tone="neutral" :href="route('lecturer.formation', $subject)">Auto-assign students</x-table.quiet-action>

                                <x-table.quiet-action tone="neutral" :href="route('lecturer.rubrics', $subject)">Rubric</x-table.quiet-action>

                                {{-- Destructive: quiet, but always there. --}}
                                <x-table.quiet-action icon="trash"
                                    aria-label="Delete {{ $subject->code }}"
                                    x-on:click="confirm = {
                                        url: '{{ route('lecturer.subjects.destroy', $subject) }}',
                                        method: 'DELETE',
                                        title: 'Delete this subject?',
                                        body: {{ Js::from($deleteBody) }},
                                        consequences: {{ Js::from($consequences) }},
                                        confirmText: {{ Js::from($subject->code) }},
                                        action: 'Delete subject',
                                        tone: 'danger',
                                    }">Delete</x-table.quiet-action>

                                <x-slot:primary>
                                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                              :href="route('lecturer.subjects.edit', $subject)">Edit</x-button>
                                </x-slot:primary>
                            </x-table.row-actions>
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
