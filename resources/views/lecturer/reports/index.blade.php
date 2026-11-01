<x-layouts.app title="Contribution reports — CollabSpace"
               heading="Contribution reports"
               subtitle="Who actually did the work — measured from board, chat and document activity.">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <x-subject-section-filter :filter="$filter" route="lecturer.reports" />

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

            @php
                $flaggedMembers = (int) $summaries->sum('flagged');
                $totalPoints = (int) $summaries->sum('total');
            @endphp

            @if ($anyFlagged)
                <p class="-mt-2 text-meta font-medium text-ink-400" data-order-note>Groups with flagged members come first.</p>
            @endif

            <p class="@if ($anyFlagged) -mt-3 @else -mt-2 @endif text-meta font-medium text-ink-400" data-summary-line>
                {{ $groups->count() }} {{ Str::plural('group', $groups->count()) }}
                @if ($flaggedMembers > 0)
                    · {{ $flaggedMembers }} flagged {{ Str::plural('member', $flaggedMembers) }}
                @endif
                · {{ $totalPoints }} {{ Str::plural('point', $totalPoints) }} in total
            </p>

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
                                {{-- Above the card's stretched link (so a segment's own tooltip shows, not the link's), but clicks elsewhere in the box still reach the link. --}}
                                <div class="pointer-events-none relative z-[1] mt-auto flex flex-col gap-2 rounded-card bg-white/60 p-3" data-contribution>
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-nav font-extrabold">{{ $points }} {{ Str::plural('point', $points) }}</span>
                                        <span class="text-label font-medium text-ink-400">{{ $group->members_count }} {{ Str::plural('member', $group->members_count) }}</span>
                                    </div>

                                    <div class="flex h-2 w-full gap-px overflow-hidden rounded-pill bg-surface-muted" data-distribution>
                                        @foreach ($summary['segments'] as $k => $segment)
                                            @php
                                                $percent = \App\Support\ContributionTooltip::percent($segment['points'], $points);
                                                $colourOfSegment = $palette[$k % count($palette)]['accent'];
                                            @endphp
                                            <x-tooltip :label="\App\Support\ContributionTooltip::label($segment['name'], $segment['points'], $percent)"
                                                       class="cs-segment pointer-events-auto h-full rounded-[2px] focus-visible:outline-none"
                                                       style="width: {{ round($segment['points'] / $points * 100, 2) }}%; background-color: {{ $colourOfSegment }};"
                                                       data-segment data-points="{{ $segment['points'] }}">
                                                <x-slot:content>
                                                    <span class="cs-tt-row">
                                                        <span class="cs-tt-chip" style="background-color: {{ $colourOfSegment }};">{{ \App\Support\ContributionTooltip::initials($segment['name']) }}</span>
                                                        <span class="cs-tt-text">
                                                            <span class="cs-tt-name">{{ $segment['name'] }}@if ($segment['flagged']) <span class="cs-tt-low" data-low>Low</span>@endif</span>
                                                            <span class="cs-tt-sub">{{ \App\Support\ContributionTooltip::line($segment['points'], $percent) }}</span>
                                                        </span>
                                                    </span>
                                                </x-slot:content>
                                            </x-tooltip>
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

            {{-- How the points on the cards are made. Closed until asked for; the weights are read from the configuration
                 when the page is drawn, never written out here. --}}
            @php
                $labels = [
                    'task_created' => 'Task created',
                    'task_completed' => 'Task completed',
                    'message_sent' => 'Message posted (up to '.(int) config('contribution.message_cap_per_day').' a day)',
                    'file_uploaded' => 'File uploaded',
                    'document_created' => 'Document created',
                    'document_edited' => 'Document edited',
                ];
                $weights = collect(config('contribution.weights'))->filter(fn ($weight) => (int) $weight > 0);
            @endphp

            <div class="rounded-card border border-surface-line bg-surface-soft" x-data="{ open: false }" data-points-strip>
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="points-explained"
                        class="interactive flex w-full items-center gap-3 rounded-card px-5 py-3.5 text-left">
                    <x-icon name="chart" size="18" class="shrink-0 text-ink-300" />
                    <span class="min-w-0 flex-1 text-meta font-bold text-ink-600">How points are counted</span>
                    <svg class="cs-chevron size-4 shrink-0 text-ink-400" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                <x-collapse open="open" id="points-explained">
                    <div class="flex flex-col gap-3 px-5 pb-4">
                        <p class="text-meta text-ink-400">Points come from recorded activity in each group.</p>

                        <dl class="grid max-w-md grid-cols-[1fr_auto] gap-x-6 gap-y-1.5 text-meta" data-weights>
                            @foreach ($weights as $type => $weight)
                                <dt class="text-ink-600">{{ $labels[$type] ?? str($type)->replace('_', ' ')->ucfirst() }}</dt>
                                <dd class="text-right font-bold tabular-nums">{{ (int) $weight }} {{ Str::plural('point', (int) $weight) }}</dd>
                            @endforeach
                        </dl>

                        <p class="text-meta text-ink-400">Each colour in the bar on a card is one member, hover to see the name and points.</p>
                    </div>
                </x-collapse>
            </div>
        @endif
    </div>

</x-layouts.app>