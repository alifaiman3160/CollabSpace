<x-layouts.app title="Dashboard — CollabSpace"
               :heading="'Good day, '.Str::of(auth()->user()->name)->explode(' ')->take(2)->implode(' ')"
               :subtitle="$counts['overdue'] > 0
                   ? $counts['overdue'].' '.Str::plural('task', $counts['overdue']).' overdue across your groups.'
                   : 'Nothing overdue across your groups today.'">

    <x-slot:actions>
        <x-button variant="icon" aria-label="Notifications">
            <x-icon name="bell" />
        </x-button>
        <x-button :href="route('lecturer.groups.create')" icon="plus">Add one group</x-button>
    </x-slot:actions>

    @php
        $activityLabels = [
            'task_created' => 'created a task',
            'task_completed' => 'completed a task',
            'message_sent' => 'posted a message',
            'file_uploaded' => 'uploaded a file',
            'document_created' => 'created a document',
            'document_edited' => 'edited a document',
        ];
        $activityIcons = [
            'task_created' => 'plus',
            'task_completed' => 'check',
            'message_sent' => 'chat',
            'file_uploaded' => 'file',
            'document_created' => 'file',
            'document_edited' => 'edit',
        ];
    @endphp

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- One card per subject, in the subject's own colour (SubjectPalette), the same card the student sees.
             The main area opens the subject; the announcements strip along the bottom is the shared one. Cards are
             top-aligned, so opening one strip never stretches the others. They wrap into a grid, never a scroller. --}}
        @if ($cards->isEmpty())
            <x-empty-state
                class="!border-solid !border-surface-line"
                icon="book"
                title="No subjects yet"
                body="Create a subject, then add groups to it. Students are assigned to a group once it exists."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            <section class="grid grid-cols-[repeat(auto-fit,minmax(15rem,1fr))] items-start gap-[18px]" aria-label="My subjects">
                @foreach ($cards as $i => $card)
                    @php
                        $subject = $card['subject'];
                        $colour = \App\Support\SubjectPalette::for($subject->id);
                        $style = "background-color: {$colour['bg']}; border-color: {$colour['border']};".($i === 0 ? " box-shadow: 0 0 0 2px {$colour['accent']};" : '');
                    @endphp
                    <div class="flex min-w-0 flex-col rounded-panel border text-ink" style="{{ $style }}"
                         data-subject-card data-palette="{{ $colour['name'] }}">

                        <a href="{{ route('lecturer.subjects.edit', $subject) }}"
                           class="motion-colors flex min-h-[11.5rem] flex-col justify-between gap-4 rounded-panel p-5 hover:bg-white/50"
                           aria-label="Open {{ $subject->name }}, {{ $subject->code }}">
                            <span class="flex items-start justify-between gap-2">
                                <span class="rounded-pill px-2.5 py-0.5 text-nav font-extrabold tracking-[-0.02em]" style="background-color: {{ $colour['accent'] }};">{{ $subject->code }}</span>
                                <span class="flex size-[22px] items-center justify-center rounded-pill bg-white/80 text-meta font-bold text-ink-600" aria-hidden="true">›</span>
                            </span>

                            <span class="flex flex-col gap-1">
                                <span class="truncate text-label font-semibold text-ink-600">{{ $subject->name }}</span>
                                <span class="text-h2 font-extrabold leading-[1.1] tracking-title">
                                    {{ $card['groups'] }} {{ Str::plural('group', $card['groups']) }}
                                </span>
                            </span>

                            <span class="grid grid-cols-3 gap-2 text-label font-semibold text-ink-600" data-subject-stats>
                                <span class="flex flex-col"><span class="text-meta font-extrabold text-ink">{{ $card['students'] }}</span>{{ Str::plural('student', $card['students']) }}</span>
                                <span class="flex flex-col" title="{{ $card['tasks_done'] }} of {{ $card['tasks_total'] }} tasks done"><span class="text-meta font-extrabold text-ink">{{ $card['progress'] }}%</span>tasks done</span>
                                <span class="flex flex-col"><span class="text-meta font-extrabold {{ $card['overdue'] > 0 ? 'text-danger-text' : 'text-ink' }}">{{ $card['overdue'] }}</span>overdue</span>
                            </span>
                        </a>

                        <x-announcement-strip :items="$announcements->get($subject->id, collect())" :colour="$colour"
                                              :suffix="$subject->id" :subject-id="$subject->id"
                                              :audience="(int) ($audience[$subject->id] ?? 0)" :opened="$opened"
                                              :post-url="route('lecturer.announcements', $subject)" />
                    </div>
                @endforeach
            </section>
        @endif

        {{-- Totals --}}
        <section class="flex flex-wrap gap-3.5" aria-label="Teaching totals">
            <x-stat-card size="sm" icon="alert" icon-tone="danger"
                         label="Needs attention" :value="$counts['needing_attention']"
                         :meta="$counts['needing_attention'] ? $counts['needing_attention'].' '.Str::plural('group', $counts['needing_attention']).' with overdue tasks' : 'No group is behind'" />

            <x-stat-card size="sm" icon="edit" icon-tone="info"
                         label="Awaiting marking" :value="$awaitingMarking['students']"
                         :meta="$awaitingMarking['subjects'] ? 'Students without approved marks' : 'No rubric set up yet'" />

            <x-stat-card size="sm" icon="folder" icon-tone="info"
                         label="Groups" :value="$counts['groups']"
                         :meta="$counts['subjects'] ? 'Across your subjects' : 'None yet'" />

            <x-stat-card size="sm" icon="graduation" icon-tone="info"
                         label="Students" :value="$counts['students']"
                         :meta="$counts['students'] ? 'Enrolled in your groups' : 'None assigned'" />
        </section>

        {{-- Quick access --}}
        <section class="flex flex-wrap gap-2.5" aria-label="Quick access">
            <x-button :href="route('lecturer.groups')" icon="folder">Groups</x-button>
            <x-button variant="secondary" :href="route('lecturer.marking')" icon="edit">Marking</x-button>
            <x-button variant="secondary" :href="route('lecturer.progress')" icon="chart">Monitor progress</x-button>
            <x-button variant="secondary" :href="route('lecturer.students')" icon="graduation">Students</x-button>
        </section>

        {{-- Two panels of the same height, side by side (stacked on a narrow screen), each with a header, a body
             that scrolls inside the panel, and a footer with its one link. --}}
        <div class="grid gap-[18px] lg:h-[26rem] lg:grid-cols-3 lg:items-stretch" data-bottom-panels>

            <section class="flex min-h-[23.75rem] flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle lg:col-span-2" aria-label="Groups needing attention">
                <h2 class="px-5 pb-2 pt-4 text-h3 font-extrabold tracking-title">Groups needing attention</h2>

                <div class="flex min-h-0 flex-1 flex-col overflow-y-auto" data-attention-list>
                    @forelse ($needingAttention as $group)
                        <div class="motion-colors flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-surface-line/60 px-5 py-3 first:border-t-0 hover:bg-surface-soft" data-attention-row>
                            <div class="flex min-w-[10rem] flex-1 flex-col gap-1">
                                <a href="{{ route('lecturer.groups.show', $group) }}" class="interactive truncate rounded-md text-nav font-bold">{{ $group->name }}</a>
                                <span class="w-fit"><x-badge tone="info" :title="$group->subject->name">{{ $group->subject->code }}</x-badge></span>
                            </div>

                            <x-table.capacity :names="$group->members->pluck('name')" :count="$group->members_count" :size="$group->group_size" />

                            <x-table.progress :done="$group->tasks_done" :total="$group->tasks_total" :overdue="$group->tasks_overdue" />

                            <div class="ml-auto flex items-center gap-1.5">
                                <x-table.quiet-action tone="neutral" :href="route('lecturer.reports.show', $group)">Report</x-table.quiet-action>
                                <x-button :href="route('lecturer.progress.show', $group)" class="!px-4 !py-2 !text-meta">Monitor</x-button>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center gap-2 px-5 py-6 text-center" data-attention-empty>
                            <span class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300">
                                <x-icon name="folder" size="20" />
                            </span>
                            <span class="text-meta font-semibold text-ink-400">No groups yet. Add a group to a subject and it appears here.</span>
                        </div>
                    @endforelse
                </div>

                <div class="border-t border-surface-line px-5 py-3">
                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.groups')">Manage groups</x-button>
                </div>
            </section>

            {{-- Recent activity across the lecturer's groups (the same log the Monitor screen reads). --}}
            <section class="flex min-h-[23.75rem] flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle" aria-label="Recent activity">
                <h2 class="px-5 pb-2 pt-4 text-h3 font-extrabold tracking-title">Recent activity</h2>

                <div class="flex max-h-[26rem] min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto px-4 pb-3" data-activity-list>
                    @forelse ($recentActivity as $entry)
                        <article class="flex items-start gap-2.5 rounded-card bg-surface-soft p-3.5">
                            <x-avatar :name="$entry->user->name" size="sm" />
                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-meta font-bold">{{ $entry->user->name }}</span>
                                    <span class="shrink-0 text-tiny text-ink-300">{{ $entry->created_at->diffForHumans(short: true) }}</span>
                                </div>
                                <p class="line-clamp-2 text-meta text-ink-400">
                                    {{ $activityLabels[$entry->activity_type] ?? $entry->activity_type }} · {{ $entry->group->name }}
                                </p>
                            </div>
                        </article>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2 py-6 text-center" data-activity-empty>
                            <span class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300">
                                <x-icon name="chart" size="20" />
                            </span>
                            <span class="text-meta font-semibold text-ink-400">Nothing has happened in your groups yet.</span>
                        </div>
                    @endforelse
                </div>

                <div class="border-t border-surface-line px-5 py-3">
                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.progress')">Monitor progress</x-button>
                </div>
            </section>
        </div>
    </div>

</x-layouts.app>
