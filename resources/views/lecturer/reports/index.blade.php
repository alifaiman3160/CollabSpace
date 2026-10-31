<x-layouts.app title="Contribution reports — CollabSpace"
               heading="Contribution reports"
               subtitle="Who actually did the work — measured from board, chat and document activity.">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        @if ($groups->isEmpty())
            <x-empty-state
                class="!border-solid !border-surface-line"
                icon="chart"
                title="No groups to report on"
                body="Contribution is measured per group. Create a subject and a group, then assign students to it."
                cta="Go to Groups"
                :href="route('lecturer.groups')" />
        @else
            @php
                $anyFlagged = $summaries->contains(fn ($s) => $s['flagged'] > 0);
                // With more than four groups they sit under their subject's own heading; otherwise one grid.
                $sections = $groups->count() > 4 ? $groups->groupBy('subject_id') : collect([null => $groups]);
                $n = 0;
            @endphp

            @if ($anyFlagged)
                <p class="-mt-2 text-meta font-medium text-ink-400" data-order-note>Groups with flagged members come first.</p>
            @endif

            @foreach ($sections as $subjectId => $section)
                <section class="flex flex-col gap-3" aria-label="{{ $subjectId ? $section->first()->subject->code : 'Select group' }}">
                    <h2 class="text-h3 font-extrabold tracking-title">
                        @if ($subjectId)
                            {{ $section->first()->subject->code }}
                            <span class="font-semibold text-ink-400">· {{ $section->first()->subject->name }}</span>
                        @else
                            Select group
                        @endif
                    </h2>

                    <div class="grid grid-cols-[repeat(auto-fill,minmax(20rem,1fr))] gap-[18px]" data-report-grid>
                        @foreach ($section as $group)
                            @php
                                $summary = $summaries[$group->id];
                                $points = $summary['total'];
                                $palette = \App\Support\SubjectPalette::COLOURS;
                            @endphp

                            <x-group-card :group="$group" :i="$n++" variant="contribution" stretch
                                          :href="route('lecturer.reports.show', $group)">
                                @if ($summary['flagged'] > 0)
                                    <x-slot:badge>
                                        <span data-flag-pill class="inline-flex shrink-0 items-center rounded-pill bg-danger-bg px-2.5 py-0.5 text-label font-bold text-danger-text">
                                            {{ $summary['flagged'] }} flagged
                                        </span>
                                    </x-slot:badge>
                                @endif

                                {{-- How the points are shared: one segment per member who has any, in proportion to their points. --}}
                                <div class="mt-auto flex flex-col gap-2 rounded-card bg-white/60 p-3" data-contribution>
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-nav font-extrabold">{{ $points }} {{ Str::plural('point', $points) }}</span>
                                        <span class="text-label font-medium text-ink-400">{{ $group->members_count }} {{ Str::plural('member', $group->members_count) }}</span>
                                    </div>

                                    <div class="flex h-2 w-full gap-px overflow-hidden rounded-pill bg-surface-muted" data-distribution>
                                        @foreach ($summary['segments'] as $k => $segment)
                                            <span class="h-full"
                                                  style="width: {{ round($segment['points'] / $points * 100, 2) }}%; background-color: {{ $palette[$k % count($palette)]['accent'] }};"
                                                  title="{{ $segment['name'] }}: {{ $segment['points'] }} {{ Str::plural('point', $segment['points']) }}"
                                                  data-segment data-points="{{ $segment['points'] }}"></span>
                                        @endforeach
                                    </div>
                                </div>

                                <x-slot:footer>
                                    <x-button :href="route('lecturer.reports.show', $group)" class="!px-4 !py-2 !text-meta">View report</x-button>
                                </x-slot:footer>
                            </x-group-card>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>

</x-layouts.app>