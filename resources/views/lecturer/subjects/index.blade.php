<x-layouts.app title="My subjects — CollabSpace"
               heading="My subjects"
               :subtitle="$subjects->count().' '.Str::plural('subject', $subjects->count()).' you teach.'">

    <x-slot:actions>
        <x-button :href="route('lecturer.subjects.create')" icon="plus">New subject</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        @if ($subjects->isEmpty())
            <div class="mx-auto flex w-full max-w-md flex-col items-center gap-4 py-6 text-center" data-subjects-empty>
                <p class="text-body leading-[1.55] text-ink-400">No subjects yet. A subject holds the groups your students work in; create your first one to get started.</p>

                <a href="{{ route('lecturer.subjects.create') }}" data-new-subject-tile
                   class="cs-card-in cs-lift interactive flex min-h-[14rem] w-full flex-col items-center justify-center gap-3 rounded-panel border border-surface-strong bg-surface-soft px-6 py-8 text-center">
                    <span class="flex size-14 items-center justify-center rounded-pill bg-surface text-ink shadow-subtle"><x-icon name="plus" size="24" /></span>
                    <span class="text-nav font-extrabold tracking-title">New subject</span>
                    <span class="text-label font-medium text-ink-400">Add a course you teach</span>
                </a>
            </div>
        @else
            <section class="grid grid-cols-[repeat(auto-fill,minmax(24rem,1fr))] gap-[18px]" aria-label="Subjects" data-subject-grid>
                @foreach ($subjects as $i => $subject)
                    @php
                        // The project deadline, with the same flag the table gave it: overdue, or due within a week.
                        $deadline = $subject->project_deadline ? \Illuminate\Support\Carbon::parse($subject->project_deadline) : null;
                        $days = $deadline ? now()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false) : null;
                        $deadlinePill = $deadline === null ? null : ($days < 0 ? ['danger', 'Overdue'] : ($days <= 7 ? ['warning', $days === 0 ? 'Due today' : 'Due in '.$days.' '.Str::plural('day', $days)] : null));

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

                    <x-subject-card :subject="$subject" :i="$i" :href="route('lecturer.subjects.edit', $subject)"
                        :stats="[
                            ['label' => 'Groups', 'value' => $subject->groups_count, 'href' => $subject->groups_count > 0 ? route('lecturer.groups', ['subject' => $subject->id]) : null],
                            ['label' => 'Students', 'value' => $subject->group_members_count],
                            ['label' => 'Default size', 'value' => $subject->group_size],
                            ['label' => 'Group project due', 'value' => $deadline ? $deadline->format('j M Y') : 'No deadline', 'pill' => $deadlinePill],
                        ]">
                        <x-slot:footer>
                            <x-button :href="route('lecturer.subjects.edit', $subject)" class="!px-4 !py-2 !text-meta">Edit</x-button>

                            <x-table.quiet-action tone="neutral" :href="route('lecturer.groups', ['subject' => $subject->id])">Groups</x-table.quiet-action>
                            <x-table.quiet-action tone="neutral" :href="route('lecturer.formation', $subject)">Auto-assign students</x-table.quiet-action>
                            <x-table.quiet-action tone="neutral" :href="route('lecturer.rubrics', $subject)">Rubric</x-table.quiet-action>
                            <x-table.quiet-action tone="neutral" :href="route('lecturer.announcements', $subject)">Announcements</x-table.quiet-action>

                            {{-- Destructive: quiet, but always there. --}}
                            <x-table.quiet-action icon="trash" class="ml-auto"
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
                        </x-slot:footer>
                    </x-subject-card>
                @endforeach

                {{-- The last item: starts a subject, exactly like the New subject button above (same route, same permission). --}}
                <a href="{{ route('lecturer.subjects.create') }}" data-new-subject-tile
                   class="cs-card-in cs-lift interactive flex min-h-[18rem] flex-col items-center justify-center gap-3 rounded-panel border border-surface-strong bg-surface-soft px-6 py-8 text-center"
                   style="--i: {{ min($subjects->count(), 12) }};">
                    <span class="flex size-14 items-center justify-center rounded-pill bg-surface text-ink shadow-subtle"><x-icon name="plus" size="24" /></span>
                    <span class="text-nav font-extrabold tracking-title">New subject</span>
                    <span class="text-label font-medium text-ink-400">Add a course you teach</span>
                </a>
            </section>

            {{-- Under the cards: what is coming, and what was last said. Two panels of one height, the dashboard's style. --}}
            @php
                $today = now()->startOfDay();
                $bySubject = $subjects->keyBy('id');
            @endphp

            <div class="grid gap-[18px] lg:h-[24rem] lg:grid-cols-2 lg:items-stretch" data-subject-panels>

                <x-panel title="Upcoming deadlines" :count="$deadlines->count()" class="cs-card-in lg:min-h-0" style="--i: {{ min($subjects->count() + 1, 12) }}" data-deadlines-panel>
                    @forelse ($deadlines as $subject)
                        @php
                            $colour = \App\Support\SubjectPalette::for($subject->id);
                            $date = \Illuminate\Support\Carbon::parse($subject->project_deadline)->startOfDay();
                            $days = (int) $today->diffInDays($date, false);
                        @endphp
                        <a href="{{ route('lecturer.subjects.edit', $subject) }}"
                           class="motion-colors interactive flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-card px-2 py-3 hover:bg-surface-soft" data-deadline-row>
                            <span class="shrink-0 rounded-pill px-2.5 py-0.5 text-label font-extrabold" style="background-color: {{ $colour['accent'] }};">{{ $subject->code }}</span>
                            <span class="min-w-0 flex-1 truncate text-nav font-bold" title="{{ $subject->name }}">{{ $subject->name }}</span>
                            <span class="shrink-0 text-meta font-medium tabular-nums text-ink-400">{{ $date->format('j M Y') }}</span>
                            @if ($days < 0)
                                <x-table.dot-pill tone="danger">Overdue by {{ abs($days) }} {{ Str::plural('day', abs($days)) }}</x-table.dot-pill>
                            @elseif ($days === 0)
                                <x-table.dot-pill tone="warning">Today</x-table.dot-pill>
                            @else
                                <span class="shrink-0 text-meta font-semibold text-ink-600">in {{ $days }} {{ Str::plural('day', $days) }}</span>
                            @endif
                        </a>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center gap-3 px-2 py-8 text-center" data-deadlines-empty>
                            <span class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300"><x-icon name="calendar" size="20" /></span>
                            <span class="text-meta font-semibold text-ink-400">No project deadlines set yet.</span>
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.subjects.edit', $subjects->first())">Edit a subject</x-button>
                        </div>
                    @endforelse
                </x-panel>

                <x-panel title="Recent announcements" class="cs-card-in lg:min-h-0" style="--i: {{ min($subjects->count() + 2, 12) }}" data-announcements-panel>
                    @forelse ($announcements as $announcement)
                        @php
                            $subject = $bySubject->get($announcement->subject_id);
                            $colour = \App\Support\SubjectPalette::for($announcement->subject_id);
                        @endphp
                        <a href="{{ route('lecturer.announcements', $announcement->subject_id) }}"
                           class="motion-colors interactive flex flex-col gap-1 rounded-card px-2 py-3 hover:bg-surface-soft" data-announcement-row>
                            <span class="flex items-center gap-2">
                                <span class="shrink-0 rounded-pill px-2.5 py-0.5 text-label font-extrabold" style="background-color: {{ $colour['accent'] }};">{{ $subject?->code }}</span>
                                <span class="min-w-0 flex-1 truncate text-nav font-bold" title="{{ $announcement->title }}">{{ $announcement->title }}</span>
                            </span>
                            <span class="text-label font-medium text-ink-400">
                                {{ $announcement->created_at->diffForHumans() }}
                                · Opened by {{ (int) ($opened[$announcement->id] ?? 0) }} of {{ (int) ($audience[$announcement->subject_id] ?? 0) }}
                            </span>
                        </a>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2 py-8 text-center" data-announcements-empty>
                            <span class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300"><x-icon name="bell" size="20" /></span>
                            <span class="text-meta font-semibold text-ink-400">No announcements yet.</span>
                        </div>
                    @endforelse

                    <x-slot:footer>
                        {{-- One subject: straight to its announcements. Several: a short list to choose from. --}}
                        @if ($subjects->count() === 1)
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.announcements', $subjects->first())" data-post-announcement>Post announcement</x-button>
                        @else
                            <div class="relative inline-block" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                                <x-button variant="secondary" class="!px-4 !py-2 !text-meta" x-on:click="open = ! open" aria-haspopup="true" x-bind:aria-expanded="open" data-post-announcement>Post announcement</x-button>

                                <div x-show="open" x-cloak class="absolute bottom-full left-0 z-30 mb-2 w-72 rounded-card border border-surface-strong bg-surface p-2 shadow-card">
                                    <p class="px-3 pb-1.5 pt-2 text-label font-bold uppercase tracking-eyebrow text-ink-300">For which subject?</p>
                                    @foreach ($subjects as $option)
                                        <a href="{{ route('lecturer.announcements', $option) }}" class="interactive flex flex-col rounded-[12px] px-3 py-2.5 hover:bg-surface-hover">
                                            <span class="text-nav font-bold">{{ $option->code }}</span>
                                            <span class="truncate text-label text-ink-400">{{ $option->name }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </x-slot:footer>
                </x-panel>
            </div>
        @endif
        <x-confirm-dialog />
    </div>

</x-layouts.app>
