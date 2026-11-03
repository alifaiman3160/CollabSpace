@php
    use App\Models\Task;

    $meta = [
        Task::STATUS_TODO => ['title' => 'To Do', 'well' => 'bg-surface-muted', 'pill' => 'bg-surface-muted text-ink-500'],
        Task::STATUS_INPROGRESS => ['title' => 'In Progress', 'well' => 'bg-periwinkle-soft', 'pill' => 'bg-periwinkle-soft text-periwinkle-link'],
        Task::STATUS_DONE => ['title' => 'Done', 'well' => 'bg-success-bg', 'pill' => 'bg-success-bg text-success-text'],
    ];

    $palette = \App\Support\SubjectPalette::for($group->subject_id);
    $activityLabels = \App\Support\ActivityLabels::LABELS;
@endphp

{{-- The back link is withheld when the lecturer has only one group, because
     the picker redirects straight here in that case — the link would look like
     a way out and land you back on the same screen. --}}
<x-layouts.app title="Monitor progress — CollabSpace"
               :heading="$group->name"
               :subtitle="$group->subject->code.' — '.$group->subject->name.($group->section ? ' · Section '.$group->section->name : '')"
               :back="$groups->count() > 1 ? route('lecturer.progress') : null"
               back-label="All groups">

    <x-slot:actions>
        <x-lecturer.group-switcher :groups="$groups" :current="$group" route="lecturer.progress.show" />
        <x-button variant="secondary" :href="route('lecturer.reports.show', $group)">
            <x-icon name="chart" size="16" />
            Contribution report
        </x-button>
        <x-button variant="secondary" :href="route('lecturer.groups.show', $group)">Manage members</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <x-assessment-filter :assessments="$assessments" :current="$assessment?->id" route="lecturer.progress.show" :params="[$group]" />

        @if ($assessment)
            <x-lecturer.submission-card :group="$group" :assessment="$assessment" />
        @endif

        {{-- Five tiles of one component and one height; the first wears the group's own colour. --}}
        <section class="grid grid-cols-2 gap-3.5 min-[900px]:grid-cols-5" aria-label="This group" data-tiles>
            <x-stat-card size="sm" icon="users" icon-tone="neutral" :palette="$palette" class="cs-card-in" style="--i: 0"
                         label="Members" :value="$group->members->count().' / '.$group->group_size" :meta="$group->subject->code" />

            <x-stat-card size="sm" icon="check" icon-tone="success" class="cs-card-in" style="--i: 1"
                         label="Tasks done" :value="$taskDone" :meta="'of '.$taskTotal" />

            <x-stat-card size="sm" icon="clock" icon-tone="info" class="cs-card-in" style="--i: 2"
                         label="In progress" :value="$taskDoing" meta="Being worked on" />

            <x-stat-card size="sm" icon="alert" icon-tone="danger" class="cs-card-in" style="--i: 3"
                         label="Overdue" :value="$taskOverdue" :meta="$taskOverdue ? 'Past deadline' : 'On schedule'" />

            <x-stat-card size="sm" icon="chart" icon-tone="info" class="cs-card-in" style="--i: 4"
                         label="Task progress" :value="$taskProgress.'%'"
                         :meta="$taskTotal > 0 ? $taskDone.' of '.$taskTotal.' '.Str::plural('task', $taskTotal) : 'No tasks yet'">
                @if ($taskOverdue > 0)
                    <x-table.dot-pill tone="danger" class="w-fit">{{ $taskOverdue }} overdue</x-table.dot-pill>
                @endif
                <x-progress-bar :done="$taskDone" :total="$taskTotal" height="6px" />
            </x-stat-card>
        </section>

        {{-- The board, read only, in one card. The three columns are as tall as the tallest of them up to a limit, and
             scroll inside when they have more cards than that. --}}
        <x-panel title="Task board" class="cs-card-in !min-h-0" style="--i: 5" data-board-panel>
            <x-slot:aside>Read-only — students move their own cards.</x-slot:aside>

            <div class="grid items-stretch gap-3 pt-1 md:grid-cols-3" data-board
                 x-data="{ doneOpen: null, toggleDone(id, el) { this.doneOpen = this.doneOpen === id ? null : id; if (this.doneOpen === null) return; setTimeout(() => el.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }), 300) } }">
                @foreach ($columns as $status => $tasks)
                    @php($m = $meta[$status])
                    <section class="flex min-h-0 min-w-0 flex-col gap-3 rounded-panel p-4 {{ $m['well'] }}" aria-label="{{ $m['title'] }} column" data-column>
                        <div class="flex items-center gap-2.5 px-1">
                            <h3 class="text-[16px] font-extrabold tracking-[-0.025em]">{{ $m['title'] }}</h3>
                            <span class="flex h-[22px] min-w-6 items-center justify-center rounded-pill bg-surface px-[7px] text-label font-bold text-ink-500">
                                {{ $tasks->count() }}
                            </span>
                        </div>

                        <div class="cs-scroll flex max-h-[26rem] min-h-[7rem] min-h-0 flex-1 flex-col gap-3 overflow-y-auto pr-1" data-column-cards>
                            @forelse ($tasks as $task)
                                @if ($status === Task::STATUS_DONE)
                                    <x-task-done-row :task="$task" :readonly="true" wire:key="done-{{ $task->id }}" />
                                    @continue
                                @endif

                                <article class="relative flex shrink-0 flex-col gap-3 overflow-hidden rounded-card bg-surface p-[15px] shadow-subtle" data-board-card>
                                    @if ($task->isOverdue())
                                        <span class="absolute inset-y-0 left-0 w-1 bg-danger" aria-hidden="true"></span>
                                    @endif

                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-pill px-[11px] py-[4.5px] text-label font-bold {{ $m['pill'] }}">{{ $m['title'] }}</span>
                                        @if ($task->isOverdue())
                                            <x-badge tone="danger">
                                                <x-icon name="clock" size="12" />
                                                Overdue
                                            </x-badge>
                                        @endif
                                    </div>

                                    <h4 class="text-pretty text-[15px] font-bold leading-[1.35] tracking-[-0.015em]">{{ $task->title }}</h4>

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
                                <p class="rounded-card bg-surface px-4 py-6 text-center text-meta text-ink-400">{{ $status === Task::STATUS_DONE ? 'Nothing done yet.' : 'Nothing here.' }}</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </x-panel>

        {{-- Underneath: members and documents on the left, the group's talk on the right in one card with two tabs. --}}
        <div class="grid gap-[18px] lg:grid-cols-2" data-monitor-panels>

            <div class="flex flex-col gap-[18px]">

                <x-panel title="Member activity" :count="$report['members']->count()" class="cs-card-in !min-h-0 lg:max-h-[24rem]" style="--i: 6">
                    <div class="flex flex-col gap-2">
                        @foreach ($report['members'] as $member)
                            <article class="flex flex-wrap items-center gap-3 rounded-card bg-surface-soft p-3" data-member-row>
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
                                        <x-badge tone="danger" data-tooltip="Contribution is well below the rest of the group">Low</x-badge>
                                    @endif
                                    <span class="rounded-pill bg-surface-muted px-3 py-1.5 text-meta font-bold text-ink-600">{{ $member['score'] }} pts</span>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <p class="mt-auto px-1 pt-3 text-label text-ink-400">
                        @if ($report['hasActivity'])
                            Average {{ round($report['average'], 1) }} · low-participation threshold {{ round($report['threshold'], 1) }}.
                        @else
                            Nothing has been recorded for this group yet.
                        @endif
                    </p>
                </x-panel>

                {{-- The group's writing, read only. Counts alone cannot tell a lecturer whether eleven edits produced a
                     chapter or eleven rewrites of a heading. --}}
                <x-panel title="Documents" :count="$documents->count()" class="cs-card-in !min-h-0 lg:max-h-[22rem]" style="--i: 7">
                    @forelse ($documents as $document)
                        <a href="{{ route('lecturer.progress.documents.show', [$group, $document]) }}"
                           class="motion-colors interactive flex items-center gap-3 rounded-card px-2 py-2.5 hover:bg-surface-soft">
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

                            <span class="shrink-0 text-tiny text-ink-300" title="{{ $document->updated_at->format('j F Y, g:ia') }}">
                                {{ $document->updated_at->diffForHumans(short: true) }}
                            </span>
                        </a>
                    @empty
                        <p class="px-2 py-6 text-center text-meta text-ink-400">This group has not created any documents yet.</p>
                    @endforelse

                    @if ($deletedDocuments->isNotEmpty())
                        {{-- Removed by the group, kept for the lecturer: closed until asked for. --}}
                        <div class="mt-2 border-t border-surface-line pt-2" x-data="{ deleted: false }">
                            <button type="button" x-on:click="deleted = ! deleted" x-bind:aria-expanded="deleted" aria-controls="deleted-documents"
                                    class="interactive flex w-full items-center justify-between gap-2 rounded-card px-2 py-2 text-meta font-bold text-ink-600 hover:bg-surface-soft">
                                <span class="flex items-center gap-2">
                                    Deleted documents
                                    <span class="rounded-pill bg-surface-muted px-2 py-0.5 text-label font-bold">{{ $deletedDocuments->count() }}</span>
                                </span>
                                <svg class="cs-chevron size-4 shrink-0" x-bind:class="deleted ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            </button>

                            <x-collapse open="deleted" id="deleted-documents" data-deleted-documents>
                                <div class="flex flex-col gap-1 pt-1">
                                    @foreach ($deletedDocuments as $document)
                                        <a href="{{ route('lecturer.progress.documents.show', [$group, $document]) }}"
                                           class="motion-colors interactive flex items-center gap-3 rounded-card px-2 py-2.5 hover:bg-surface-soft">
                                            <span class="flex size-8 shrink-0 items-center justify-center rounded-[11px] bg-surface-muted text-ink-400">
                                                <x-icon name="trash" size="15" />
                                            </span>

                                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                                <span class="truncate text-meta font-bold">{{ $document->title }}</span>
                                                <span class="text-label text-ink-400">
                                                    Deleted by {{ $document->deleter?->name ?? 'a member' }}
                                                    on {{ $document->deleted_at->format('j M Y') }}
                                                    · {{ $document->versions_count }} {{ Str::plural('version', $document->versions_count) }}
                                                </span>
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            </x-collapse>
                        </div>
                    @endif

                    @if ($documents->isNotEmpty())
                        <x-slot:footer>
                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.progress.documents', $group)">Open the full document list</x-button>
                        </x-slot:footer>
                    @endif
                </x-panel>
            </div>

            {{-- The group's talk, as tabs in one card. As tall as the left column, scrolling inside. --}}
            <div class="relative min-h-[26rem]">
                <section x-data="{ tab: 'messages' }" aria-label="Recent from this group" data-feed-tabs
                         class="cs-card-in flex flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle lg:absolute lg:inset-0" style="--i: 8">
                    <div class="flex flex-wrap items-center gap-2 px-4 pb-2 pt-4">
                        <div role="tablist" aria-label="Recent from this group" class="flex rounded-pill bg-surface-muted p-[3px]"
                             x-on:keydown.arrow-right.prevent="tab = 'activity'" x-on:keydown.arrow-left.prevent="tab = 'messages'">
                            <button type="button" role="tab" id="tab-messages" aria-controls="panel-messages" x-bind:aria-selected="tab === 'messages'"
                                    x-bind:tabindex="tab === 'messages' ? 0 : -1" x-on:click="tab = 'messages'"
                                    x-bind:class="tab === 'messages' ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink'"
                                    class="interactive flex h-8 items-center gap-1.5 rounded-pill px-4 text-meta font-bold">
                                Recent messages <span class="text-label opacity-70" data-tab-count>{{ $recentMessages->count() }}</span>
                            </button>
                            <button type="button" role="tab" id="tab-activity" aria-controls="panel-activity" x-bind:aria-selected="tab === 'activity'"
                                    x-bind:tabindex="tab === 'activity' ? 0 : -1" x-on:click="tab = 'activity'"
                                    x-bind:class="tab === 'activity' ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink'"
                                    class="interactive flex h-8 items-center gap-1.5 rounded-pill px-4 text-meta font-bold">
                                Recent activity <span class="text-label opacity-70" data-tab-count>{{ $recentActivity->count() }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-4" data-feed-body>
                        <div id="panel-messages" role="tabpanel" aria-labelledby="tab-messages" x-show="tab === 'messages'" x-transition.opacity.duration.150ms class="flex flex-col gap-2" data-messages>
                            @forelse ($recentMessages as $message)
                                <article class="flex items-start gap-2.5 rounded-card bg-surface-soft p-3">
                                    <x-avatar :name="$message->user->name" size="sm" />
                                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <span class="truncate text-meta font-bold">{{ $message->user->name }}</span>
                                            <span class="shrink-0 text-tiny text-ink-300" title="{{ $message->created_at->format('j F Y, g:ia') }}">
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
                                <p class="px-2 py-8 text-center text-meta text-ink-400">No messages in this group yet.</p>
                            @endforelse
                        </div>

                        <div id="panel-activity" role="tabpanel" aria-labelledby="tab-activity" x-show="tab === 'activity'" x-cloak x-transition.opacity.duration.150ms class="flex flex-col gap-2" data-activity>
                            @forelse ($recentActivity as $entry)
                                <div class="flex items-center gap-2.5 rounded-card bg-surface-soft px-3.5 py-2.5">
                                    <span class="truncate text-meta">
                                        <span class="font-bold">{{ $entry->user->name }}</span>
                                        <span class="text-ink-400">{{ $activityLabels[$entry->activity_type] ?? $entry->activity_type }}</span>
                                    </span>
                                    <span class="ml-auto shrink-0 text-tiny text-ink-300">{{ $entry->created_at->diffForHumans(short: true) }}</span>
                                </div>
                            @empty
                                <p class="px-2 py-8 text-center text-meta text-ink-400">Nothing recorded yet.</p>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

</x-layouts.app>