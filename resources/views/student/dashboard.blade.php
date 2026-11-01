<x-layouts.app title="Dashboard — CollabSpace"
               :heading="'Hello, '.Str::of(auth()->user()->name)->explode(' ')->first()"
               :subtitle="$hasGroup
                   ? ($pendingCount > 0
                       ? $pendingCount.' '.Str::plural('task', $pendingCount).' assigned to you are still open.'
                       : 'Nothing outstanding assigned to you right now.')
                   : 'You have not been assigned to a group yet.'">

    @if (! $hasGroup)

        {{-- A student exists before a lecturer puts them in a group. That is a
             normal state, not an error, so it gets a real screen. --}}
        <div class="flex flex-col gap-[18px]">
            <x-empty-state
                icon="folder"
                title="You are not in a group yet"
                body="Your lecturer assigns you to a group for each subject. As soon as that happens, your task board, documents and group chat appear here." />

            <div class="flex max-w-2xl items-start gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4">
                <x-icon name="alert" size="17" class="mt-0.5 shrink-0 text-ink-300" />
                <p class="text-body leading-[1.55] text-ink-400">
                    Nothing is missing on your side — there is no action to take. If you think you
                    should already be in a group, speak to your lecturer for the subject.
                </p>
            </div>

            @if ($waiting->isNotEmpty())
                <section class="grid grid-cols-1 gap-[18px] md:grid-cols-2 {{ $waiting->count() > 4 ? 'xl:grid-cols-3' : '' }}" aria-label="My subjects" data-subject-grid data-waiting-subjects>
                    @include('student._waiting-subjects')
                </section>
            @endif
        </div>

    @else

        <x-slot:actions>
            <x-button variant="icon" aria-label="Notifications">
                <x-icon name="bell" />
            </x-button>
            <x-button :href="route('student.board.show', $primaryGroup)" icon="plus">New task</x-button>
        </x-slot:actions>

        <div class="flex flex-col gap-[18px]">

            <x-flash />

            {{-- My groups, one card per group. Each card is a container: the main area is the link to the
                 workspace, and the subject's announcements, when there are any, are a separate pill-shaped
                 button along the bottom. The colour is the subject's own (see SubjectPalette), the same for
                 every student. Cards are top-aligned so opening one never stretches the others. --}}
            @php
                $announcementsBySubject = $announcements->groupBy('subject_id');
                $readIds = collect($readAnnouncementIds);
            @endphp
            <section class="grid grid-cols-1 items-stretch gap-[18px] md:grid-cols-2 {{ ($groups->count() + $waiting->count()) > 4 ? 'xl:grid-cols-3' : '' }}" aria-label="My groups" data-subject-grid>
                @foreach ($groups as $i => $g)
                    @php
                        $items = $announcementsBySubject->get($g->subject_id, collect());
                        $unreadIds = $items->pluck('id')->reject(fn ($id) => $readIds->contains($id))->values();
                        $colour = \App\Support\SubjectPalette::for($g->subject_id);
                        // The first card stands out with a 2px ring in its accent, not a heavier fill.
                        $style = "background-color: {$colour['bg']}; border-color: {$colour['border']};".($i === 0 ? " box-shadow: 0 0 0 2px {$colour['accent']};" : '');
                    @endphp
                    <div class="flex min-w-0 flex-col rounded-panel border text-ink"
                         style="{{ $style }}" data-subject-card data-palette="{{ $colour['name'] }}">

                        <a href="{{ route('student.workspace.show', $g) }}"
                           class="motion-colors flex flex-1 flex-col justify-between gap-4 rounded-panel p-5 hover:bg-white/50"
                           aria-label="Open {{ $g->name }} workspace, {{ $g->subject->code }}">
                            <span class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1.5">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="rounded-pill px-2.5 py-0.5 text-nav font-extrabold tracking-[-0.02em]" style="background-color: {{ $colour['accent'] }};">{{ $g->subject->code }}</span>
                                    <x-section-chip :section="$g->section" :code="$g->subject->code" />
                                </span>
                                <span class="ml-auto flex items-center gap-2">
                                    <x-deadline-chip :subject="$g->subject" />
                                    <span class="flex size-[22px] items-center justify-center rounded-pill bg-white/80 text-meta font-bold text-ink-600" aria-hidden="true">›</span>
                                </span>
                            </span>

                            <span class="flex flex-col gap-1">
                                <span class="truncate text-label font-semibold text-ink-600">{{ $g->subject->name }}</span>
                                <span class="text-h2 font-extrabold leading-[1.1] tracking-title">
                                    {{ $g->tasks_total }} {{ Str::plural('task', $g->tasks_total) }}
                                </span>
                            </span>

                            <span class="flex flex-wrap items-center justify-between gap-1.5 text-label font-semibold text-ink-600">
                                <span class="min-w-0 truncate">{{ $g->name }}</span>
                                <span>{{ $g->tasks_done }} done</span>
                            </span>
                        </a>

                        <x-announcement-strip :items="$items" :colour="$colour" :suffix="$g->id" :subject-id="$g->subject_id"
                                              :unread-ids="$unreadIds" :read-url="route('student.announcements.read')" />
                    </div>
                @endforeach
                {{-- Enrolled, but not in a group of that subject yet: after the ones that have a group. --}}
                @include('student._waiting-subjects')
            </section>
            {{-- My counts --}}
            <section class="flex flex-wrap gap-3.5" aria-label="My tasks">
                <x-stat-card size="sm" icon="inbox" icon-tone="info"
                             label="Pending for me" :value="$pendingCount"
                             :meta="$pendingCount ? 'Assigned and not done' : 'Nothing outstanding'" />

                <x-stat-card size="sm" icon="check" icon-tone="success"
                             label="Completed by me" :value="$completedCount"
                             meta="Counts toward your contribution" />

                <x-stat-card size="sm" icon="alert" icon-tone="danger"
                             label="Overdue" :value="$overdueCount"
                             :meta="$overdueCount ? 'Past the deadline' : 'Nothing late'" />
            </section>

            {{-- Quick access --}}
            <section class="flex flex-wrap gap-2.5" aria-label="Quick access">
                <x-button :href="route('student.board.show', $primaryGroup)" icon="grid">Task board</x-button>
                <x-button variant="secondary" :href="route('student.workspace.show', $primaryGroup)" icon="users">My workspace</x-button>
                <x-button variant="secondary" :href="route('student.documents.group', $primaryGroup)" icon="file">Documents</x-button>
                <x-button variant="secondary" :href="route('student.chat.show', $primaryGroup)" icon="chat">Group chat</x-button>
            </section>

            {{-- Two panels of the same height, side by side (stacked on a narrow screen). Each has a header,
                 a body that fills the space, and a footer with its one link. --}}
            <div class="grid gap-[18px] lg:h-[26rem] lg:grid-cols-3 lg:items-stretch" data-bottom-panels>

                {{-- Pending tasks: up to five, in columns that line up. --}}
                <section class="flex max-h-[26rem] min-h-[23.75rem] flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle lg:col-span-2" aria-label="My pending tasks">
                    <h2 class="px-5 pb-2 pt-4 text-h3 font-extrabold tracking-title">My pending tasks</h2>

                    @php
                        $shownTasks = $pendingTasks->take(5);
                        // Three columns of fixed width beside the flexible one; stacked rows below the md breakpoint.
                        $cols = 'md:grid md:grid-cols-[minmax(0,1fr)_5.5rem_13rem_6.5rem] md:items-center md:gap-x-4';
                        $soonWithin = (int) config('notifications.deadline_reminder_days', 2);
                    @endphp

                    <div class="flex min-h-0 flex-1 flex-col">
                        @if ($shownTasks->isNotEmpty())
                            <div class="hidden px-5 pb-1.5 text-label font-bold uppercase tracking-eyebrow text-ink-300 md:grid md:grid-cols-[minmax(0,1fr)_5.5rem_13rem_6.5rem] md:gap-x-4" aria-hidden="true" data-pending-head>
                                <span>Task</span><span>Subject</span><span>Deadline</span><span>Status</span>
                            </div>

                            <ul class="flex flex-col divide-y divide-surface-line/60" data-pending-list>
                                @foreach ($shownTasks as $task)
                                    @php
                                        $days = $task->deadline ? now()->startOfDay()->diffInDays($task->deadline->copy()->startOfDay(), false) : null;
                                        $late = $days !== null && $days < 0;
                                        $soon = $days !== null && ! $late && $days <= $soonWithin;
                                    @endphp
                                    <li @class([
                                            'motion-colors relative flex flex-wrap items-center gap-x-3 gap-y-1.5 px-5 py-3',
                                            $cols,
                                            'bg-danger-bg/40 hover:bg-danger-bg/70' => $late,
                                            'bg-warning-bg/50 hover:bg-warning-bg/80' => $soon,
                                            'hover:bg-surface-soft' => ! $late && ! $soon,
                                        ])
                                        data-pending-row @if ($late) data-state="overdue" @elseif ($soon) data-state="soon" @endif>
                                        @if ($late || $soon)
                                            <span @class(['absolute inset-y-1.5 left-0 w-1 rounded-r-pill', 'bg-danger/50' => $late, 'bg-warning/60' => $soon]) aria-hidden="true" data-row-bar></span>
                                        @endif

                                        <a href="{{ route('student.board.show', $task->group) }}"
                                           class="interactive flex min-w-0 basis-full flex-col rounded-md md:basis-auto">
                                            <span class="truncate text-nav font-bold">{{ $task->title }}</span>
                                            <span class="truncate text-label text-ink-400">{{ $task->group->name }}</span>
                                        </a>

                                        <span class="justify-self-start"><x-badge tone="info" :title="$task->group->subject->name">{{ $task->group->subject->code }}</x-badge></span>

                                        <span class="flex items-center gap-2 whitespace-nowrap" data-deadline>
                                            @if ($task->deadline)
                                                <span class="text-meta font-medium tabular-nums text-ink-600" title="{{ $task->deadline->format('j F Y') }}">{{ $task->deadline->format('j M Y') }}</span>
                                                @if ($late)
                                                    <span class="inline-flex items-center gap-1 rounded-pill bg-danger-bg px-2 py-0.5 text-label font-bold text-danger-text" data-deadline-badge="overdue">
                                                        <x-icon name="clock" size="11" />Overdue
                                                    </span>
                                                @elseif ($soon)
                                                    <span class="inline-flex items-center gap-1 rounded-pill bg-warning-bg px-2 py-0.5 text-label font-bold text-warning-text" data-deadline-badge="soon">
                                                        <x-icon name="calendar" size="11" />{{ $days === 0 ? 'Due today' : 'Due in '.$days.' '.Str::plural('day', $days) }}
                                                    </span>
                                                @endif
                                            @else
                                                <span class="text-meta text-ink-300">—</span>
                                            @endif
                                        </span>

                                        <span class="justify-self-start">
                                            <x-badge :tone="$task->status === 'inprogress' ? 'info' : 'neutral'">
                                                {{ $task->status === 'inprogress' ? 'In progress' : 'To do' }}
                                            </x-badge>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Its own mint box, not a line under the list: what is left of the panel when there are fewer than five. --}}
                        @if ($shownTasks->count() < 5)
                            <div class="flex flex-1 px-5 pb-4 pt-4" data-pending-box>
                                <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-card border-2 border-dashed border-success-line bg-success-bg px-5 py-6 text-center" data-pending-empty>
                                    <span class="flex size-10 items-center justify-center rounded-pill bg-surface text-success-text">
                                        <x-icon name="check" size="18" />
                                    </span>
                                    <span class="text-meta font-semibold text-success-text">
                                        {{ $shownTasks->isEmpty() ? 'You have no pending tasks.' : 'Nothing else pending. Nice work.' }}
                                    </span>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-surface-line px-5 py-3">
                        <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('student.board.show', $primaryGroup)">Open board</x-button>
                    </div>
                </section>
                {{-- Recent chat: scrolls inside its panel. --}}
                <section class="flex max-h-[26rem] min-h-[23.75rem] flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle" aria-label="Recent chat">
                    <h2 class="px-5 pb-2 pt-4 text-h3 font-extrabold tracking-title">Recent chat</h2>

                    <div class="flex max-h-[26rem] min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto px-4 pb-3" data-chat-list>
                        @forelse ($recentMessages as $message)
                            <article class="flex items-start gap-2.5 rounded-card bg-surface-soft p-3.5">
                                <x-avatar :name="$message->user->name" size="sm" />
                                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-meta font-bold">{{ $message->user->name }}</span>
                                        <span class="shrink-0 text-tiny text-ink-300">
                                            {{ $message->created_at->diffForHumans(short: true) }}
                                        </span>
                                    </div>
                                    <p class="line-clamp-2 text-meta text-ink-400">
                                        @if ($message->hasAttachment())
                                            <span class="inline-flex items-center gap-1 font-semibold text-ink-600">
                                                <x-icon name="file" size="12" />{{ $message->file_name }}
                                            </span>
                                        @else
                                            {{ $message->body }}
                                        @endif
                                    </p>
                                </div>
                            </article>
                        @empty
                            <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2 py-6 text-center">
                                <span class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300">
                                    <x-icon name="chat" size="20" />
                                </span>
                                <span class="text-meta font-semibold text-ink-400">No messages in your groups yet.</span>
                            </div>
                        @endforelse
                    </div>

                    <div class="border-t border-surface-line px-5 py-3">
                        <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('student.chat.show', $primaryGroup)">Open chat</x-button>
                    </div>
                </section>
            </div>
        </div>

    @endif

</x-layouts.app>
