@php
    // Forming groups is per subject. With one chosen, or only one to choose from,
    // it is a plain link; otherwise the header offers a short list to pick from.
    $formationSubject = $subjectId
        ? $subjects->firstWhere('id', $subjectId)
        : ($subjects->count() === 1 ? $subjects->first() : null);

    $oneHelp = 'Creates a single empty group. You place the members yourself.';
    $autoHelp = 'Distributes unassigned students across several groups at once: balanced by CGPA, or imported from a form.';
@endphp

<x-layouts.app title="Groups — CollabSpace"
               heading="Groups"
               :subtitle="$groups->count().' '.Str::plural('group', $groups->count()).
                   ($subjectId ? ' in '.$subjects->firstWhere('id', $subjectId)->code : ' across your subjects').'.'">

    <x-slot:actions>
        @if ($subjects->isNotEmpty())
            {{-- The two ways of making groups, side by side and always there:
                 form them automatically, or create one by hand. The first used to
                 vanish whenever no subject was chosen, which left a lecturer with
                 several subjects thinking the feature did not exist. --}}
            @if ($formationSubject)
                <x-button variant="secondary" :href="route('lecturer.classlist', $formationSubject)" icon="graduation" data-class-list-link>
                    Class list
                </x-button>
                <x-button variant="secondary" :href="route('lecturer.formation', $formationSubject)" icon="users"
                          title="{{ $autoHelp }}">
                    Auto-assign students
                </x-button>
            @else
                <div class="relative"
                     x-data="{ open: false }"
                     x-on:click.outside="open = false"
                     x-on:keydown.escape.window="open = false">
                    <x-button variant="secondary" icon="users" x-on:click="open = ! open"
                              title="{{ $autoHelp }}"
                              aria-haspopup="true" x-bind:aria-expanded="open">
                        Auto-assign students
                    </x-button>

                    <div x-show="open" x-cloak
                         x-transition:enter="transition ease-motion duration-motion-slow"
                         x-transition:enter-start="opacity-0 scale-[.96]"
                         x-transition:enter-end="opacity-100 scale-100"
                         class="absolute right-0 z-30 mt-2 w-72 rounded-card border border-surface-strong bg-surface p-2 shadow-card">
                        <p class="px-3 pb-1.5 pt-2 text-label font-bold uppercase tracking-eyebrow text-ink-300">
                            For which subject?
                        </p>

                        @foreach ($subjects as $option)
                            <a href="{{ route('lecturer.formation', $option) }}"
                               class="flex flex-col rounded-[12px] px-3 py-2.5 interactive hover:bg-surface-hover">
                                <span class="text-nav font-bold">{{ $option->code }}</span>
                                <span class="truncate text-label text-ink-400">{{ $option->name }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <x-button :href="route('lecturer.groups.create', $subjectId ? ['subject' => $subjectId] : [])" icon="plus"
                      title="{{ $oneHelp }}">
                Add one group
            </x-button>
        @endif
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        <x-subject-section-filter :filter="$filter" route="lecturer.groups" />

        @if ($subjects->isNotEmpty())
            {{-- The two buttons above look alike to someone new, so say what each does: two small tiles, side by side. --}}
            <div class="grid gap-3 md:grid-cols-2" data-group-help>
                @foreach ([['plus', 'Add one group', $oneHelp], ['users', 'Auto-assign students', $autoHelp]] as [$icon, $title, $text])
                    <div class="flex items-start gap-3 rounded-card border border-surface-line bg-surface p-4 shadow-subtle">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-periwinkle-soft text-periwinkle-link"><x-icon :name="$icon" size="17" /></span>
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="text-nav font-bold">{{ $title }}</span>
                            <span class="text-meta leading-[1.5] text-ink-400">{{ $text }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        @if ($subjects->isEmpty())
            <x-empty-state
                class="!border-solid !border-surface-line"
                icon="book"
                title="No subjects yet"
                body="Groups live inside a subject. Create a subject first."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            {{-- Only for a subject that has several sections; a single-section subject has nothing to compare. --}}
            @if ($subjectId && $sections->isNotEmpty() && $sectionCounts->isNotEmpty())
                <p class="text-meta font-medium text-ink-600" data-section-summary>
                    {{ $sectionCounts->map(fn ($c) => 'Section '.$c['name'].': '.$c['groups'].' '.Str::plural('group', $c['groups']))->implode(' · ') }}
                </p>
            @endif

            @if ($groups->isEmpty())
                <x-empty-state
                    class="!border-solid !border-surface-line"
                    icon="folder"
                    title="No groups here yet"
                    body="Create a group by hand and assign students to it, or let CollabSpace form them: balanced by CGPA, or imported from a Google Form where students chose their own."
                    cta="Add one group"
                    :href="route('lecturer.groups.create', $subjectId ? ['subject' => $subjectId] : [])">
                    @if ($formationSubject)
                        <x-button variant="secondary" :href="route('lecturer.formation', $formationSubject)" icon="users">
                            Auto-assign students
                        </x-button>
                    @endif
                </x-empty-state>
            @else
                <section class="grid grid-cols-[repeat(auto-fill,minmax(20rem,1fr))] gap-[18px]" aria-label="Groups" data-group-grid>
                    @foreach ($groups as $i => $group)
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

                        <x-group-card :group="$group" :i="$i" :href="route('lecturer.groups.show', $group)">
                            <x-slot:footer>
                                <x-button :href="route('lecturer.groups.show', $group)" class="!px-4 !py-2 !text-meta">Manage</x-button>

                                <x-table.quiet-action
                                    aria-label="Delete {{ $group->name }}" icon="trash"
                                    x-on:click="confirm = {
                                        url: '{{ route('lecturer.groups.destroy', $group) }}',
                                        method: 'DELETE',
                                        title: 'Delete this group?',
                                        body: {{ Js::from($deleteBody) }},
                                        consequences: {{ Js::from($consequences) }},
                                        confirmText: {{ Js::from($group->name) }},
                                        action: 'Delete group',
                                        tone: 'danger',
                                    }">Delete</x-table.quiet-action>
                            </x-slot:footer>
                        </x-group-card>
                    @endforeach
                </section>
            @endif
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
