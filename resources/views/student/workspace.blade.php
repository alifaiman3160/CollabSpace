<x-layouts.app title="My workspace — CollabSpace"
               :heading="$hasGroup ? $group->name : 'My workspace'"
               :subtitle="$hasGroup ? $group->subject->code.' — '.$group->subject->name.($group->section ? ' · Section '.$group->section->name : '') : 'You are not in a group yet.'">

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
            $palette = \App\Support\SubjectPalette::for($group->subject_id);
            $openTotal = $group->tasks_total - $group->tasks_done;

            $activityLabels = [
                'task_created' => 'created a task',
                'task_completed' => 'completed a task',
                'message_sent' => 'posted a message',
                'file_uploaded' => 'uploaded a file',
                'document_created' => 'created a document',
                'document_edited' => 'edited a document',
                'document_deleted' => 'removed a document',
            ];
            $activityIcons = [
                'task_created' => 'plus',
                'task_completed' => 'check',
                'message_sent' => 'chat',
                'file_uploaded' => 'file',
                'document_created' => 'file',
                'document_edited' => 'edit',
                'document_deleted' => 'trash',
            ];
        @endphp

        <div class="flex flex-col gap-[18px]">

            <x-flash />

            <x-group-picker :groups="$groups" :current="$group" route="student.workspace.show" :secondary="$secondary" />

            {{-- The project this group exists to produce: a card that starts closed. --}}
            <x-project-brief :subject="$group->subject" />

            {{-- Five tiles of one component and one height. The first wears the group's own soft colour. --}}
            <section class="grid grid-cols-2 gap-3.5 min-[900px]:grid-cols-5" aria-label="This group" data-tiles>
                <x-stat-card size="sm" icon="users" icon-tone="neutral" :palette="$palette" class="cs-card-in" style="--i: 0"
                             label="Members" :value="$group->members->count().' / '.$group->group_size"
                             :meta="$group->subject->code" />

                <x-stat-card size="sm" icon="check" icon-tone="success" class="cs-card-in" style="--i: 1"
                             label="Tasks done" :value="$group->tasks_done"
                             :meta="'of '.$group->tasks_total" />

                <x-stat-card size="sm" icon="clock" icon-tone="info" class="cs-card-in" style="--i: 2"
                             label="In progress" :value="$group->tasks_doing"
                             meta="Being worked on" />

                <x-stat-card size="sm" icon="alert" icon-tone="danger" class="cs-card-in" style="--i: 3"
                             label="Overdue" :value="$group->tasks_overdue"
                             :meta="$group->tasks_overdue ? 'Past deadline' : 'On schedule'" />

                <x-stat-card size="sm" icon="chart" icon-tone="info" class="cs-card-in" style="--i: 4"
                             label="Task progress" :value="$progress.'%'"
                             :meta="$group->tasks_total > 0 ? $group->tasks_done.' of '.$group->tasks_total.' '.Str::plural('task', $group->tasks_total) : 'No tasks yet'">
                    @if ($group->tasks_overdue > 0)
                        <x-table.dot-pill tone="danger" class="w-fit">{{ $group->tasks_overdue }} overdue</x-table.dot-pill>
                    @endif
                    <x-progress-bar :done="$group->tasks_done" :total="$group->tasks_total" height="6px" />
                </x-stat-card>
            </section>

            {{-- Two columns: members and open tasks on the left, the feed on the right. The feed lives inside its own card
                 and scrolls there, so it can never make the page taller than the left column. --}}
            <div class="grid gap-[18px] lg:grid-cols-3" data-workspace-panels>

                <div class="flex flex-col gap-[18px] lg:col-span-2">

                    <x-panel title="Group members" :count="$group->members->count()" class="cs-card-in" style="--i: 5">
                        <div class="flex flex-col gap-2" data-member-list>
                            @foreach ($group->members as $member)
                                @php
                                    $score = (int) ($scores[$member->id] ?? 0);
                                    $isLow = $average > 0 && $score < $threshold;
                                @endphp
                                <article class="flex min-h-[3.75rem] items-center gap-3 rounded-card bg-surface-soft px-3.5 py-2.5" data-member-row>
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
                        </div>

                        <p class="mt-auto px-1 pt-3 text-label text-ink-400">
                            Points come from recorded activity — tasks, documents, uploads and messages.
                            Group average is {{ round($average, 1) }}.
                        </p>
                    </x-panel>

                    <x-panel title="Open tasks" :count="$openTotal" class="cs-card-in" style="--i: 6">
                        @forelse ($openTasks as $task)
                            <div class="motion-colors flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-surface-line/60 px-1.5 py-3 first:border-t-0 hover:bg-surface-soft" data-open-task>
                                <div class="flex min-w-[10rem] flex-1 flex-col">
                                    <span class="truncate text-nav font-bold">{{ $task->title }}</span>
                                    <span class="text-label text-ink-400">{{ $task->status === 'inprogress' ? 'In progress' : 'To do' }}</span>
                                </div>

                                <div class="flex w-44 shrink-0 items-center gap-2">
                                    @if ($task->assignee)
                                        <x-avatar :name="$task->assignee->name" size="sm" />
                                        <span class="truncate text-meta font-medium text-ink-600">{{ $task->assignee->name }}</span>
                                    @else
                                        <span class="text-meta text-ink-300">Unassigned</span>
                                    @endif
                                </div>

                                <x-table.date :date="$task->deadline" class="w-32 shrink-0" />
                            </div>
                        @empty
                            <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2 py-8 text-center" data-open-empty>
                                <span class="flex size-11 items-center justify-center rounded-pill bg-success-bg text-success-text">
                                    <x-icon name="check" size="20" />
                                </span>
                                <span class="text-meta font-semibold text-ink-400">Nothing open. Nice work.</span>
                            </div>
                        @endforelse

                        <x-slot:footer>
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('student.board.show', $group)">Open board</x-button>
                        </x-slot:footer>
                    </x-panel>
                </div>

                {{-- Recent activity. The wrapper is as tall as the left column; the panel fills it and scrolls inside. --}}
                <div class="relative min-h-[23.75rem]">
                    <x-panel title="Recent activity" class="cs-card-in lg:absolute lg:inset-0 lg:min-h-0" style="--i: 7" data-feed-panel>
                        <div class="flex flex-col gap-2" data-feed>
                            @forelse ($recentActivity as $entry)
                                <article class="flex items-start gap-2.5 rounded-card bg-surface-soft p-3">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-[11px] bg-surface text-ink-600">
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
                                <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2 py-8 text-center">
                                    <span class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300">
                                        <x-icon name="chart" size="20" />
                                    </span>
                                    <span class="text-meta font-semibold text-ink-400">Nothing recorded in this group yet.</span>
                                </div>
                            @endforelse
                        </div>

                        <x-slot:footer>
                            <span class="text-label text-ink-400">
                                @if ($recentActivity->isNotEmpty())
                                    The latest {{ $recentActivity->count() }}, newest first.
                                @else
                                    Activity appears here as the group works.
                                @endif
                            </span>
                        </x-slot:footer>
                    </x-panel>
                </div>
            </div>
        </div>
    @endif

</x-layouts.app>
