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

            {{-- My groups, one card per group, per the dashboard design --}}
            <section class="flex flex-wrap gap-3.5" aria-label="My groups">
                @foreach ($groups as $i => $g)
                    <div class="flex min-w-[150px] flex-1 flex-col justify-between gap-4 rounded-panel p-5
                                {{ $i === 0 ? 'bg-periwinkle' : 'bg-surface-muted' }}">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-nav font-extrabold tracking-[-0.02em]">{{ $g->subject->code }}</span>
                            <a href="{{ route('student.workspace.show', $g) }}"
                               class="flex size-[22px] items-center justify-center rounded-pill bg-white/70 text-meta font-bold text-ink-400 hover:text-ink"
                               aria-label="Open {{ $g->name }} workspace">›</a>
                        </div>

                        <div class="flex flex-col gap-1">
                            <span class="text-label font-semibold text-ink/[.6]">{{ $g->subject->name }}</span>
                            <span class="text-h2 font-extrabold leading-[1.1] tracking-title">
                                {{ $g->tasks_total }} {{ Str::plural('task', $g->tasks_total) }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-1.5 text-label font-semibold text-ink/[.6]">
                            <span>{{ $g->name }}</span>
                            <span>{{ $g->tasks_done }} done</span>
                        </div>
                    </div>
                @endforeach
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

            <div class="grid gap-[18px] lg:grid-cols-3">

                {{-- Pending tasks --}}
                <section class="flex flex-col gap-3 lg:col-span-2">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-h3 font-extrabold tracking-title">My pending tasks</h2>
                        <x-button variant="secondary" :href="route('student.board.show', $primaryGroup)">Open board</x-button>
                    </div>

                    <x-data-table :headings="['Task', 'Group', 'Deadline', 'Status']"
                                  empty="Nothing assigned to you is outstanding.">
                        @foreach ($pendingTasks as $task)
                            <tr class="hover:bg-surface-soft">
                                <td>
                                    <a href="{{ route('student.board.show', $task->group) }}"
                                       class="text-nav font-bold hover:text-periwinkle-link">{{ $task->title }}</a>
                                </td>

                                <td>
                                    <x-badge tone="info" :title="$task->group->subject->name">
                                        {{ $task->group->subject->code }}
                                    </x-badge>
                                </td>

                                <td class="whitespace-nowrap">
                                    @if ($task->deadline)
                                        <span class="text-meta font-semibold {{ $task->isOverdue() ? 'text-danger-text' : 'text-ink-400' }}">
                                            {{ $task->deadline->format('j M Y') }}
                                        </span>
                                    @else
                                        <span class="text-meta text-ink-300">No deadline</span>
                                    @endif
                                </td>

                                <td>
                                    <span class="flex flex-wrap gap-1.5">
                                        @if ($task->isOverdue())
                                            <x-badge tone="danger">
                                                <x-icon name="clock" size="12" />
                                                Overdue
                                            </x-badge>
                                        @endif
                                        <x-badge :tone="$task->status === 'inprogress' ? 'info' : 'neutral'">
                                            {{ $task->status === 'inprogress' ? 'In progress' : 'To do' }}
                                        </x-badge>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                </section>

                {{-- Recent chat --}}
                <section class="flex flex-col gap-3">
                    <h2 class="text-h3 font-extrabold tracking-title">Recent chat</h2>

                    <div class="flex flex-1 flex-col gap-2.5 rounded-panel border border-surface-line bg-surface-soft p-4">
                        @forelse ($recentMessages as $message)
                            <article class="flex items-start gap-2.5 rounded-card bg-surface p-3.5">
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
                            <p class="px-2 py-6 text-center text-meta text-ink-400">
                                No messages in your groups yet.
                            </p>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

    @endif

</x-layouts.app>
