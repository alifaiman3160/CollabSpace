@php
    use App\Models\Task;

    $meta = [
        Task::STATUS_TODO => ['title' => 'To Do', 'well' => 'bg-surface-muted', 'pill' => 'bg-surface-muted text-ink-500'],
        Task::STATUS_INPROGRESS => ['title' => 'In Progress', 'well' => 'bg-periwinkle-soft', 'pill' => 'bg-periwinkle-soft text-periwinkle-link'],
        Task::STATUS_DONE => ['title' => 'Done', 'well' => 'bg-success-bg', 'pill' => 'bg-success-bg text-success-text'],
    ];

    $activityLabels = [
        'task_created' => 'created a task',
        'task_completed' => 'completed a task',
        'message_sent' => 'posted a message',
        'file_uploaded' => 'uploaded a file',
        'document_created' => 'created a document',
        'document_edited' => 'edited a document',
    ];
@endphp

{{-- The back link is withheld when the lecturer has only one group, because
     the picker redirects straight here in that case — the link would look like
     a way out and land you back on the same screen. --}}
<x-layouts.app title="Monitor progress — CollabSpace"
               :heading="$group->name"
               :subtitle="$group->subject->code.' — '.$group->subject->name"
               :back="$groups->count() > 1 ? route('lecturer.progress') : null"
               back-label="All groups">

    <x-slot:actions>
        <x-button variant="secondary" :href="route('lecturer.reports.show', $group)">
            <x-icon name="chart" size="16" />
            Contribution report
        </x-button>
        <x-button variant="secondary" :href="route('lecturer.groups.show', $group)">Manage members</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- Group switcher --}}
        @if ($groups->count() > 1)
            <div class="flex flex-wrap rounded-pill bg-surface-muted p-[3px]">
                @foreach ($groups as $g)
                    <a href="{{ route('lecturer.progress.show', $g) }}"
                       title="{{ $g->subject->name }}"
                       class="flex h-8 items-center rounded-pill px-[15px] text-meta font-bold interactive
                              {{ $g->is($group) ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                        {{ $g->name }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Overview --}}
        <section class="flex flex-wrap gap-3.5">
            <x-stat-card size="sm" tone="periwinkle" icon="users" icon-tone="neutral"
                         label="Members" :value="$group->members->count().' / '.$group->group_size"
                         :meta="$group->subject->code" />

            <x-stat-card size="sm" icon="check" icon-tone="success"
                         label="Tasks done" :value="$taskDone" :meta="'of '.$taskTotal" />

            <x-stat-card size="sm" icon="clock" icon-tone="info"
                         label="In progress" :value="$taskDoing" meta="Being worked on" />

            <x-stat-card size="sm" icon="alert" icon-tone="danger"
                         label="Overdue" :value="$taskOverdue"
                         :meta="$taskOverdue ? 'Past deadline' : 'On schedule'" />

            <div class="flex min-w-[220px] flex-1 flex-col justify-center rounded-panel border border-surface-line bg-surface shadow-subtle p-[18px]">
                <x-task-progress :done="$taskDone" :total="$taskTotal" :overdue="$taskOverdue" />
            </div>
        </section>

        {{-- Read-only board --}}
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Task board</h2>
                <span class="text-meta text-ink-400">Read-only — students move their own cards.</span>
            </div>

            <div class="flex gap-4 overflow-x-auto pb-2">
                @foreach ($columns as $status => $tasks)
                    @php($m = $meta[$status])
                    <section class="flex min-w-[280px] flex-1 flex-col gap-3 rounded-panel border border-surface-line p-4 {{ $m['well'] }}"
                             aria-label="{{ $m['title'] }} column">
                        <div class="flex items-center gap-2.5 px-1">
                            <h3 class="text-[16px] font-extrabold tracking-[-0.025em]">{{ $m['title'] }}</h3>
                            <span class="flex h-[22px] min-w-6 items-center justify-center rounded-pill bg-surface px-[7px] text-label font-bold text-ink-500">
                                {{ $tasks->count() }}
                            </span>
                        </div>

                        <div class="flex flex-col gap-3">
                            @forelse ($tasks as $task)
                                <article class="relative flex flex-col gap-2.5 overflow-hidden rounded-card bg-surface p-[15px] shadow-subtle">
                                    @if ($task->isOverdue())
                                        <span class="absolute inset-y-0 left-0 w-1 bg-danger" aria-hidden="true"></span>
                                    @endif

                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-pill px-[11px] py-[4.5px] text-label font-bold {{ $m['pill'] }}">
                                            {{ $m['title'] }}
                                        </span>
                                        @if ($task->isOverdue())
                                            <x-badge tone="danger">
                                                <x-icon name="clock" size="12" />
                                                Overdue
                                            </x-badge>
                                        @endif
                                    </div>

                                    <h4 class="text-pretty text-[15px] font-bold leading-[1.35] tracking-[-0.015em]">
                                        {{ $task->title }}
                                    </h4>

                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-2">
                                            @if ($task->assignee)
                                                <x-avatar :name="$task->assignee->name" size="sm" class="!size-[27px] !text-micro" />
                                                <span class="truncate text-meta font-semibold text-ink-600">{{ $task->assignee->name }}</span>
                                            @else
                                                <span class="text-meta font-semibold text-ink-300">Unassigned</span>
                                            @endif
                                        </span>

                                        @if ($task->deadline)
                                            <span class="shrink-0 text-meta font-semibold {{ $task->isOverdue() ? 'text-danger-text' : 'text-ink-400' }}">
                                                {{ $task->deadline->format('j M') }}
                                            </span>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <p class="rounded-card bg-surface px-4 py-6 text-center text-meta text-ink-400">
                                    Nothing here.
                                </p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </section>

        <div class="grid gap-[18px] lg:grid-cols-2">

            {{-- Member activity --}}
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Member activity</h2>

                <div class="flex flex-col gap-2.5 rounded-panel border border-surface-line bg-surface-soft p-4">
                    @foreach ($report['members'] as $member)
                        <article class="flex flex-wrap items-center gap-3 rounded-card bg-surface p-3.5">
                            <x-avatar :name="$member['user']->name" size="sm" />

                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate text-nav font-bold">{{ $member['user']->name }}</span>
                                <span class="text-label text-ink-400">
                                    {{ $member['counts']['tasks_done'] }} done ·
                                    {{ $member['counts']['messages'] }} messages ·
                                    {{ $member['counts']['documents'] }} doc edits ·
                                    {{ $member['counts']['files'] }} files
                                </span>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($member['flagged'])
                                    <x-badge tone="danger">Low</x-badge>
                                @endif
                                <span class="rounded-pill bg-surface-muted px-3 py-1.5 text-meta font-bold text-ink-600">
                                    {{ $member['score'] }} pts
                                </span>
                            </div>
                        </article>
                    @endforeach

                    @if ($report['hasActivity'])
                        <p class="px-1 pt-1 text-label text-ink-400">
                            Average {{ round($report['average'], 1) }} · low-participation threshold
                            {{ round($report['threshold'], 1) }}.
                        </p>
                    @else
                        <p class="px-1 pt-1 text-label text-ink-400">Nothing has been recorded for this group yet.</p>
                    @endif
                </div>

                {{-- The group's writing, read-only. Counts alone cannot tell a
                     lecturer whether eleven edits produced a chapter or eleven
                     rewrites of a heading. --}}
                <h2 class="mt-2 text-h3 font-extrabold tracking-title">Documents</h2>

                <div class="flex flex-col gap-2.5 rounded-panel border border-surface-line bg-surface-soft p-4">
                    @forelse ($documents as $document)
                        <a href="{{ route('lecturer.documents.show', $document) }}"
                           class="flex items-center gap-3 rounded-card bg-surface p-3.5 card-hover">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-[11px] bg-surface-muted text-ink-400">
                                <x-icon name="file" size="15" />
                            </span>

                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate text-meta font-bold">{{ $document->title }}</span>
                                <span class="text-label text-ink-400">
                                    {{ $document->versions_count }} {{ Str::plural('version', $document->versions_count) }}
                                    · last edited by {{ $document->lastEditor?->name ?? 'unknown' }}
                                </span>
                            </span>

                            <span class="shrink-0 text-tiny text-ink-300"
                                  title="{{ $document->updated_at->format('j F Y, g:ia') }}">
                                {{ $document->updated_at->diffForHumans(short: true) }}
                            </span>
                        </a>
                    @empty
                        <p class="px-2 py-6 text-center text-meta text-ink-400">
                            This group has not created any documents yet.
                        </p>
                    @endforelse

                    @if ($documents->isNotEmpty())
                        <a href="{{ route('lecturer.documents', $group) }}"
                           class="px-1 pt-1 text-label font-semibold text-periwinkle-link hover:underline">
                            Open the full document list
                        </a>
                    @endif
                </div>
            </section>

            {{-- Recent messages --}}
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Recent messages</h2>

                <div class="flex flex-1 flex-col gap-2.5 rounded-panel border border-surface-line bg-surface-soft p-4">
                    @forelse ($recentMessages as $message)
                        <article class="flex items-start gap-2.5 rounded-card bg-surface p-3.5">
                            <x-avatar :name="$message->user->name" size="sm" />
                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-meta font-bold">{{ $message->user->name }}</span>
                                    <span class="shrink-0 text-tiny text-ink-300"
                                          title="{{ $message->created_at->format('j F Y, g:ia') }}">
                                        {{ $message->created_at->diffForHumans(short: true) }}
                                    </span>
                                </div>
                                <p class="line-clamp-2 text-meta text-ink-400">
                                    @if ($message->hasAttachment())
                                        <span class="inline-flex items-center gap-1 font-semibold text-ink-600">
                                            <x-icon name="file" size="12" />{{ $message->file_name }}
                                        </span>
                                    @endif
                                    {{ $message->body }}
                                </p>
                            </div>
                        </article>
                    @empty
                        <p class="px-2 py-6 text-center text-meta text-ink-400">No messages in this group yet.</p>
                    @endforelse
                </div>

                <h2 class="mt-2 text-h3 font-extrabold tracking-title">Recent activity</h2>

                <div class="flex flex-col gap-2 rounded-panel border border-surface-line bg-surface-soft p-4">
                    @forelse ($recentActivity as $entry)
                        <div class="flex items-center gap-2.5 rounded-card bg-surface px-3.5 py-2.5">
                            <span class="truncate text-meta">
                                <span class="font-bold">{{ $entry->user->name }}</span>
                                <span class="text-ink-400">{{ $activityLabels[$entry->activity_type] ?? $entry->activity_type }}</span>
                            </span>
                            <span class="ml-auto shrink-0 text-tiny text-ink-300">
                                {{ $entry->created_at->diffForHumans(short: true) }}
                            </span>
                        </div>
                    @empty
                        <p class="px-2 py-4 text-center text-meta text-ink-400">Nothing recorded yet.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

</x-layouts.app>
