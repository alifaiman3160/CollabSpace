<x-layouts.app title="My workspace — CollabSpace"
               :heading="$hasGroup ? $group->name : 'My workspace'"
               :subtitle="$hasGroup ? $group->subject->code.' — '.$group->subject->name : 'You are not in a group yet.'">

    @if (! $hasGroup)

        <x-empty-state
            icon="folder"
            title="You are not in a group yet"
            body="Your lecturer assigns you to a group for each subject. Your workspace appears here once that happens." />

    @else

        <x-slot:actions>
            <x-button :href="route('student.board.show', $group)" icon="grid">Task board</x-button>
        </x-slot:actions>

        @php
            $totalScore = $scores->sum();
            $average = $group->members->count() > 0 ? $totalScore / $group->members->count() : 0;
            $threshold = $average * (float) config('contribution.low_participation_ratio');

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

            {{-- Switch between groups when the student is in more than one --}}
            @if ($groups->count() > 1)
                <div class="flex flex-wrap rounded-pill bg-surface-muted p-[3px]">
                    @foreach ($groups as $g)
                        <a href="{{ route('student.workspace.show', $g) }}"
                           title="{{ $g->subject->name }} · {{ $g->name }}"
                           class="flex h-8 items-center rounded-pill px-[15px] text-meta font-bold transition-colors
                                  {{ $g->is($group) ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                            {{ $g->subject->code }}
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
                             label="Tasks done" :value="$group->tasks_done"
                             :meta="'of '.$group->tasks_total" />

                <x-stat-card size="sm" icon="clock" icon-tone="info"
                             label="In progress" :value="$group->tasks_doing"
                             meta="Being worked on" />

                <x-stat-card size="sm" icon="alert" icon-tone="danger"
                             label="Overdue" :value="$group->tasks_overdue"
                             :meta="$group->tasks_overdue ? 'Past deadline' : 'On schedule'" />

                <div class="flex min-w-[220px] flex-1 flex-col justify-center rounded-panel border border-surface-line bg-surface shadow-subtle p-[18px]">
                    <x-task-progress :done="$group->tasks_done"
                                     :total="$group->tasks_total"
                                     :overdue="$group->tasks_overdue" />
                </div>
            </section>

            <div class="grid gap-[18px] lg:grid-cols-3">

                {{-- Members --}}
                <section class="flex flex-col gap-3 lg:col-span-2">
                    <h2 class="text-h3 font-extrabold tracking-title">Group members</h2>

                    <div class="flex flex-col gap-2.5 rounded-panel border border-surface-line bg-surface-soft p-4">
                        @foreach ($group->members as $member)
                            @php
                                $score = (int) ($scores[$member->id] ?? 0);
                                $isLow = $average > 0 && $score < $threshold;
                            @endphp
                            <article class="flex flex-wrap items-center gap-3 rounded-card bg-surface p-3.5">
                                <x-avatar :name="$member->name" size="md" />

                                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="truncate text-nav font-bold">
                                        {{ $member->name }}
                                        @if ($member->is(auth()->user()))
                                            <span class="text-meta font-semibold text-ink-300">(you)</span>
                                        @endif
                                    </span>
                                    <span class="truncate text-meta text-ink-400">
                                        {{ $member->matric_no ?? $member->email }}
                                    </span>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    @if ($isLow)
                                        <x-badge tone="danger" title="Below half the group average">Low activity</x-badge>
                                    @endif
                                    <span class="rounded-pill bg-surface-muted px-3 py-1.5 text-meta font-bold text-ink-600"
                                          title="Contribution score from recorded activity">
                                        {{ $score }} pts
                                    </span>
                                </div>
                            </article>
                        @endforeach

                        <p class="px-1 pt-1 text-label text-ink-400">
                            Points come from recorded activity — tasks, documents, uploads and messages.
                            Group average is {{ round($average, 1) }}.
                        </p>
                    </div>

                    {{-- Open work --}}
                    <h2 class="mt-2 text-h3 font-extrabold tracking-title">Open tasks</h2>

                    <x-data-table :headings="['Task', 'Assigned to', 'Deadline']"
                                  empty="Nothing outstanding — every task is done.">
                        @foreach ($openTasks as $task)
                            <tr class="hover:bg-surface-soft">
                                <td>
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-nav font-bold">{{ $task->title }}</span>
                                        @if ($task->isOverdue())
                                            <x-badge tone="danger">Overdue</x-badge>
                                        @endif
                                    </span>
                                </td>
                                <td>
                                    @if ($task->assignee)
                                        <span class="flex items-center gap-2">
                                            <x-avatar :name="$task->assignee->name" size="sm" />
                                            <span class="text-meta font-semibold text-ink-600">{{ $task->assignee->name }}</span>
                                        </span>
                                    @else
                                        <span class="text-meta text-ink-300">Unassigned</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-meta font-semibold {{ $task->isOverdue() ? 'text-danger-text' : 'text-ink-400' }}">
                                    {{ $task->deadline?->format('j M Y') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                </section>

                {{-- Recent activity --}}
                <section class="flex flex-col gap-3">
                    <h2 class="text-h3 font-extrabold tracking-title">Recent activity</h2>

                    <div class="flex flex-1 flex-col gap-2 rounded-panel border border-surface-line bg-surface-soft p-4">
                        @forelse ($recentActivity as $entry)
                            <article class="flex items-start gap-2.5 rounded-card bg-surface p-3">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-[11px] bg-surface-muted text-ink-600">
                                    <x-icon :name="$activityIcons[$entry->activity_type] ?? 'circle'" size="15" />
                                </span>
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <p class="text-meta leading-[1.4]">
                                        <span class="font-bold">{{ $entry->user->name }}</span>
                                        <span class="text-ink-400">
                                            {{ $activityLabels[$entry->activity_type] ?? $entry->activity_type }}
                                        </span>
                                    </p>
                                    <span class="text-tiny text-ink-300">
                                        {{ $entry->created_at->diffForHumans() }} · +{{ $entry->weight }} pts
                                    </span>
                                </div>
                            </article>
                        @empty
                            <p class="px-2 py-6 text-center text-meta text-ink-400">
                                Nothing recorded in this group yet.
                            </p>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>

    @endif

</x-layouts.app>
