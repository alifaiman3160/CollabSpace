<x-layouts.app title="Monitor progress — CollabSpace"
               heading="Monitor progress"
               subtitle="Pick a group to see its board, members and recent activity.">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <x-subject-section-filter :filter="$filter" route="lecturer.progress" />

        @if ($groups->isEmpty())
            <x-empty-state
                class="!border-solid !border-surface-line"
                icon="chart"
                title="No groups to monitor"
                body="Create a subject and a group, then assign students to it."
                cta="Go to Groups"
                :href="route('lecturer.groups')" />
        @else
            @php
                $average = (int) round($groups->avg(fn ($g) => $g->tasks_total > 0 ? $g->tasks_done / $g->tasks_total * 100 : 0));
                $initial = [
                    'groups' => $groups->count(),
                    'attention' => $groups->where('tasks_overdue', '>', 0)->count(),
                    'overdue' => (int) $groups->sum('tasks_overdue'),
                    'average' => $average,
                ];
                $n = 0;
            @endphp

            <div class="flex flex-col gap-[18px]" x-data="progressFilter(@js($initial))">

                {{-- Four tiles that follow the subject and section chosen above. --}}
                <section class="grid grid-cols-2 gap-3.5 min-[900px]:grid-cols-4" aria-label="Across your groups" data-tiles>
                    <x-stat-card size="sm" icon="folder" icon-tone="info" class="cs-card-in" style="--i: 0"
                                 label="Groups" :value="$initial['groups']" bind="groups" meta="Being monitored" />

                    <x-stat-card size="sm" icon="alert" icon-tone="danger" class="cs-card-in" style="--i: 1"
                                 label="Need attention" :value="$initial['attention']" bind="attention" meta="With overdue tasks" />

                    <x-stat-card size="sm" icon="clock" icon-tone="danger" class="cs-card-in" style="--i: 2"
                                 label="Overdue tasks" :value="$initial['overdue']" bind="overdue" meta="Past their deadline" />

                    <x-stat-card size="sm" icon="chart" icon-tone="success" class="cs-card-in" style="--i: 3"
                                 label="Average progress" :value="$initial['average'].'%'" bind="average + '%'" meta="Of tasks done, per group">
                        <div class="h-1.5 w-full overflow-hidden rounded-pill bg-surface-muted">
                            <div class="h-full rounded-pill bg-success" style="width: {{ $initial['average'] }}%" x-bind:style="'width: ' + average + '%'"></div>
                        </div>
                    </x-stat-card>
                </section>

                <p class="-mt-2 text-meta font-medium text-ink-400" data-order-note>Groups that need attention come first.</p>

                <section class="grid grid-cols-[repeat(auto-fill,minmax(20rem,1fr))] gap-[18px]" aria-label="Groups" data-group-grid>
                    @foreach ($groups as $group)
                        <x-group-card :group="$group" :i="$n++" :href="route('lecturer.progress.show', $group)"
                                      data-pg data-subject="{{ $group->subject_id }}" data-overdue="{{ $group->tasks_overdue }}"
                                      data-progress="{{ $group->tasks_total > 0 ? (int) round($group->tasks_done / $group->tasks_total * 100) : 0 }}">
                            <x-slot:footer>
                                <x-table.quiet-action tone="neutral" :href="route('lecturer.reports.show', $group)">Report</x-table.quiet-action>
                                <x-button :href="route('lecturer.progress.show', $group)" class="!px-4 !py-2 !text-meta">Monitor</x-button>
                            </x-slot:footer>
                        </x-group-card>
                    @endforeach
                </section>

                {{-- Two panels of one height, the dashboard's style. --}}
                <div class="grid gap-[18px] lg:h-[24rem] lg:grid-cols-2 lg:items-stretch" data-progress-panels>

                    <x-panel title="Latest activity" class="cs-card-in lg:min-h-0" style="--i: 6" data-latest-panel>
                        @forelse ($latest as $entry)
                            @php
                                $entryGroup = $groups->firstWhere('id', $entry->group_id);
                                $colour = \App\Support\SubjectPalette::for($entryGroup->subject_id);
                            @endphp
                            <a href="{{ route('lecturer.progress.show', $entryGroup) }}" data-pg-row data-subject="{{ $entryGroup->subject_id }}" data-latest-row
                               class="motion-colors interactive flex items-center gap-3 rounded-card px-2 py-2.5 hover:bg-surface-soft">
                                <x-avatar :name="$entry->user->name" size="sm" />
                                <span class="min-w-0 flex-1 truncate text-meta">
                                    <span class="font-bold">{{ $entry->user->name }}</span>
                                    <span class="text-ink-400">{{ \App\Support\ActivityLabels::label($entry->activity_type) }}</span>
                                </span>
                                <span class="max-w-[7rem] shrink-0 truncate rounded-pill px-2 py-0.5 text-label font-bold" style="background-color: {{ $colour['bg'] }}; box-shadow: inset 0 0 0 1px {{ $colour['border'] }};" title="{{ $entryGroup->name }}">{{ $entryGroup->name }}</span>
                                <span class="shrink-0 text-tiny text-ink-300">{{ $entry->created_at->diffForHumans(short: true) }}</span>
                            </a>
                        @empty
                            <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2 py-8 text-center" data-latest-empty>
                                <span class="flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300"><x-icon name="chart" size="20" /></span>
                                <span class="text-meta font-semibold text-ink-400">Nothing has happened in your groups yet.</span>
                            </div>
                        @endforelse
                    </x-panel>

                    <x-panel title="Members to check" :count="$flagged->count()" class="cs-card-in lg:min-h-0" style="--i: 7" data-flagged-panel>
                        @forelse ($flagged as $row)
                            @php $colour = \App\Support\SubjectPalette::for($row['group']->subject_id); @endphp
                            <a href="{{ route('lecturer.reports.show', $row['group']) }}" data-pg-row data-subject="{{ $row['group']->subject_id }}" data-flagged-row
                               class="motion-colors interactive flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-card px-2 py-2.5 hover:bg-surface-soft">
                                <x-avatar :name="$row['user']->name" size="sm" />
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-meta font-bold">{{ $row['user']->name }}</span>
                                    <span class="truncate text-label text-ink-400">{{ $row['score'] }} {{ Str::plural('point', $row['score']) }}</span>
                                </span>
                                <span class="max-w-[7rem] shrink-0 truncate rounded-pill px-2 py-0.5 text-label font-bold" style="background-color: {{ $colour['bg'] }}; box-shadow: inset 0 0 0 1px {{ $colour['border'] }};" title="{{ $row['group']->name }}">{{ $row['group']->name }}</span>
                                <x-table.dot-pill tone="danger">{{ $reason }}</x-table.dot-pill>
                            </a>
                        @empty
                            <div class="flex flex-1 flex-col items-center justify-center gap-2 px-2 py-8 text-center" data-flagged-empty>
                                <span class="flex size-11 items-center justify-center rounded-pill bg-success-bg text-success-text"><x-icon name="check" size="20" /></span>
                                <span class="text-meta font-semibold text-ink-400">No one flagged. Nice.</span>
                            </div>
                        @endforelse
                    </x-panel>
                </div>
            </div>
        @endif
    </div>

</x-layouts.app>