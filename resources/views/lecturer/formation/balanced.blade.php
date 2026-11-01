<x-layouts.app title="Preview groups — CollabSpace"
               :heading="'Preview groups'.($section ? ' — Section '.$section->name : '')"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('lecturer.formation', $subject)"
               back-label="Auto-assign students">

    @php
        $groups = $proposal['groups'];
        $studentCount = count($students);
        $sizes = $proposal['sizes'];
        sort($sizes);
        $sizeLabel = implode(', ', array_reverse($sizes));
    @endphp

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- The most important line on the screen. --}}
        <div class="flex items-center gap-3 rounded-card border border-periwinkle-deep bg-periwinkle-soft px-5 py-4">
            <x-icon name="eye" size="18" class="shrink-0 text-periwinkle-link" />
            <p class="min-w-0 flex-1 text-body font-bold text-ink">
                This is a preview. Nothing has been saved yet.
                <span class="font-medium text-ink-600">
                    Confirm to create these groups, or regenerate to see another arrangement.
                </span>
            </p>
        </div>

        <section class="flex flex-wrap gap-3.5" aria-label="Summary">
            <x-stat-card size="sm" tone="periwinkle" icon="users" icon-tone="neutral"
                         label="Students" :value="$studentCount"
                         :meta="'Into '.count($groups).' '.Str::plural('group', count($groups))" />

            <x-stat-card size="sm" icon="grid" icon-tone="info"
                         label="Group sizes" :value="$sizeLabel"
                         :meta="$proposal['uneven'] ? 'Not an even split' : 'All the same size'" />

            <x-stat-card size="sm" icon="chart" icon-tone="success"
                         label="Spread of averages"
                         :value="$proposal['spread'] === null ? '—' : number_format($proposal['spread'], 2)"
                         meta="Highest group minus lowest" />
        </section>

        @foreach ($proposal['warnings'] as $warning)
            <div class="flex items-start gap-3 rounded-card bg-danger-bg px-5 py-3.5">
                <x-icon name="alert" size="17" class="mt-0.5 shrink-0 text-danger-text" />
                <p class="text-body font-semibold leading-[1.5] text-danger-text">{{ $warning }}</p>
            </div>
        @endforeach

        @if ($proposal['uneven'])
            <div class="flex items-start gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-3.5">
                <x-icon name="alert" size="17" class="mt-0.5 shrink-0 text-ink-300" />
                <p class="text-meta leading-[1.55] text-ink-400">
                    {{ $studentCount }} students do not divide evenly into {{ count($groups) }} groups, so some groups
                    take one extra. The extra places go to the groups whose averages are currently highest, so the
                    group that gains a member is the one best able to absorb them.
                </p>
            </div>
        @endif

        <section class="grid gap-3.5 md:grid-cols-2" aria-label="Proposed groups">
            @foreach ($groups as $group)
                <article class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle">
                    <header class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex min-w-0 flex-col">
                            <h2 class="truncate text-h3 font-extrabold tracking-title">{{ $group['name'] }}</h2>
                            <span class="text-meta text-ink-400">
                                {{ count($group['members']) }} {{ Str::plural('member', count($group['members'])) }}
                                @if ($group['unknown'] > 0)
                                    · {{ $group['unknown'] }} with no CGPA
                                @endif
                            </span>
                        </div>

                        <div class="flex flex-col items-end">
                            <span class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Average CGPA</span>
                            <span class="text-h2 font-extrabold tracking-stat">
                                {{ $group['average'] === null ? '—' : number_format($group['average'], 2) }}
                            </span>
                        </div>
                    </header>

                    {{-- The average against the 4.00 scale: a glance for which
                         group sits where, without reading the numbers. --}}
                    @if ($group['average'] !== null)
                        <div class="h-1.5 w-full overflow-hidden rounded-pill bg-surface-muted"
                             role="img" aria-label="Average {{ number_format($group['average'], 2) }} out of 4.00">
                            <div class="h-full rounded-pill bg-ink" style="width: {{ min(100, round($group['average'] / 4 * 100)) }}%"></div>
                        </div>
                    @endif

                    <ul class="flex flex-col gap-1.5">
                        @foreach ($group['members'] as $member)
                            <li class="flex items-center gap-3 rounded-[12px] bg-surface-soft px-3 py-2">
                                <x-avatar :name="$member['name']" size="sm" />
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-meta font-bold">{{ $member['name'] }}</span>
                                    <span class="truncate text-label text-ink-400">{{ $member['matric_no'] ?? '—' }}</span>
                                </span>
                                @if ($member['cgpa'] !== null)
                                    <span class="shrink-0 text-meta font-bold text-ink-600">{{ number_format($member['cgpa'], 2) }}</span>
                                @else
                                    <span class="shrink-0 text-label text-ink-300">no CGPA</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </section>

        {{-- One set of actions, held at the bottom of the window. It used to appear
             above the groups and again below them, so the choice was never a scroll
             away, but on a short preview both showed at once and looked like two
             different confirm buttons. Stuck to the viewport it is reachable from
             anywhere on a long preview and exists exactly once. --}}
        <div class="sticky bottom-4 z-10 rounded-panel border border-surface-strong bg-surface/95 p-3 shadow-card backdrop-blur">
            @include('lecturer.formation._balanced-actions')
        </div>

        <p class="text-label leading-[1.5] text-ink-400">
            Regenerating reshuffles students whose CGPA is within {{ number_format($tolerance, 2) }} of each other and
            leaves everyone else where they are, so every arrangement keeps the groups balanced. CGPAs are entered by hand
            and may be out of date.
        </p>
    </div>

</x-layouts.app>
