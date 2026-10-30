@php
    use App\Support\ContributionReport;

    $group = $report['group'];
    $members = $report['members'];
    $total = $report['total'];
    $threshold = $report['threshold'];
    $flagged = $report['flagged'];
    $top = $report['top'];

    // Bars are drawn against the leader, so a group where everyone is low
    // still reads as a comparison rather than five identical stubs.
    $peak = max(1, (int) $members->max('score'));
@endphp

<x-layouts.app title="Contribution report — CollabSpace"
               heading="Contribution reports"
               subtitle="Who actually did the work — measured from board, chat and document activity."
               :back="route('lecturer.reports')"
               back-label="All groups">

    <div class="flex flex-col gap-[18px]">

        {{-- Group switcher --}}
        @if ($groups->count() > 1)
            <div class="flex flex-wrap rounded-pill bg-surface-muted p-[3px]">
                @foreach ($groups as $g)
                    <a href="{{ route('lecturer.reports.show', $g) }}"
                       title="{{ $g->subject->name }}"
                       class="flex h-8 items-center rounded-pill px-[15px] text-meta font-bold interactive
                              {{ $g->is($group) ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                        {{ $g->name }}
                    </a>
                @endforeach
            </div>
        @endif

        <section class="flex flex-col gap-[22px] rounded-panel border border-surface-line bg-surface-soft p-[22px]">

            {{-- Header --}}
            <div class="flex flex-wrap items-end justify-between gap-3.5">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <span class="text-label font-bold uppercase tracking-eyebrow text-ink-300">
                        {{ $group->subject->code }} · {{ $group->subject->name }} · {{ $group->name }}
                    </span>
                    <h2 class="text-h2 font-extrabold tracking-title">Contribution report</h2>
                </div>

                <div class="flex shrink-0 items-center gap-2.5">
                    <span class="flex h-10 items-center gap-2 rounded-pill bg-surface px-4 text-meta font-bold text-ink-600">
                        <x-icon name="calendar" size="15" />
                        {{ $report['generatedAt']->format('j M Y, g:ia') }}
                    </span>

                    <x-button :href="route('lecturer.reports.export', $group)" class="!h-10 !py-0">
                        <x-icon name="download" size="15" />
                        Export
                    </x-button>
                </div>
            </div>

            @if (! $report['hasActivity'])

                {{-- A group that has not started is not a group of free riders. --}}
                <div class="flex items-start gap-3.5 rounded-[20px] bg-surface px-[18px] py-4">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-pill bg-surface-muted text-ink-400">
                        <x-icon name="inbox" size="17" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="text-body font-extrabold tracking-[-0.015em]">No activity recorded yet</span>
                        <span class="text-pretty text-meta leading-[1.5] text-ink-400">
                            Nothing has been logged for {{ $group->name }} — no tasks completed, no messages,
                            no document edits. Every member is listed below at zero. Nobody is flagged, because
                            there is no average to compare against yet.
                        </span>
                    </div>
                </div>

            @endif

            {{-- Summary --}}
            <div class="flex flex-wrap gap-3">
                <div class="flex min-w-[140px] flex-[1_1_150px] flex-col gap-1.5 rounded-[20px] bg-periwinkle px-[18px] py-4">
                    <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Group score</span>
                    <span class="text-[28px] font-extrabold leading-none tracking-stat">{{ $total }}</span>
                    <span class="text-label font-semibold text-ink/[.6]">activity points logged</span>
                </div>

                <div class="flex min-w-[140px] flex-[1_1_150px] flex-col gap-1.5 rounded-[20px] bg-surface px-[18px] py-4">
                    <span class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Balance</span>
                    <span class="text-[28px] font-extrabold leading-none tracking-stat">
                        {{ $top ? round($top['share']).'%' : '—' }}
                    </span>
                    <span class="truncate text-label font-semibold text-ink-400">
                        {{ $top ? 'carried by '.$top['user']->name : 'no contributions yet' }}
                    </span>
                </div>

                <div class="flex min-w-[140px] flex-[1_1_150px] flex-col gap-1.5 rounded-[20px] bg-surface px-[18px] py-4">
                    <span class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Flagged</span>
                    <span class="text-[28px] font-extrabold leading-none tracking-stat {{ $flagged->isNotEmpty() ? 'text-danger-text' : '' }}">
                        {{ $flagged->count() }}
                    </span>
                    <span class="text-label font-semibold text-ink-400">
                        @if ($report['hasActivity'])
                            below {{ round($threshold, 1) }} points
                        @else
                            nothing to compare yet
                        @endif
                    </span>
                </div>
            </div>

            {{-- How the threshold was reached: the basis, not just the verdict. --}}
            <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 rounded-[20px] bg-surface px-[18px] py-3.5 text-meta text-ink-400">
                <span><span class="font-bold text-ink">{{ $members->count() }}</span> members</span>
                <span>Average <span class="font-bold text-ink">{{ round($report['average'], 1) }}</span></span>
                <span>
                    Threshold <span class="font-bold text-ink">{{ round($threshold, 1) }}</span>
                    ({{ round($report['ratio'] * 100) }}% of the average)
                </span>
                <span class="text-ink-300">
                    Weights:
                    @foreach ($report['weights'] as $type => $weight)
                        {{ str($type)->replace('_', ' ') }} {{ $weight }}@if (! $loop->last), @endif
                    @endforeach
                </span>
            </div>

            {{-- Bar chart comparing members --}}
            <div class="flex flex-col gap-3">
                @foreach ($members as $member)
                    <div class="flex flex-wrap items-center gap-3.5 rounded-card bg-surface px-4 py-3.5">
                        <div class="flex min-w-[170px] flex-[1_1_190px] items-center gap-2.5">
                            <x-avatar :name="$member['user']->name" size="sm" class="!size-[34px]" />
                            <div class="flex min-w-0 flex-col gap-1">
                                <span class="truncate text-body font-bold tracking-[-0.015em]">{{ $member['user']->name }}</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($member['flagged'])
                                        <x-badge tone="danger">Low participation</x-badge>
                                    @elseif ($top && $member['user']->is($top['user']))
                                        <x-badge tone="success">Top contributor</x-badge>
                                    @else
                                        <x-badge tone="neutral">{{ $member['score'] }} pts</x-badge>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex min-w-[180px] flex-[3_1_240px] items-center gap-3">
                            <div class="h-3 flex-1 overflow-hidden rounded-pill bg-surface-muted"
                                 role="img"
                                 aria-label="{{ $member['user']->name }}: {{ $member['score'] }} points, {{ round($member['share'], 1) }} percent of the group total">
                                <div class="h-3 rounded-pill {{ $member['flagged'] ? 'bg-danger' : 'bg-ink' }}"
                                     style="width: {{ $member['score'] > 0 ? max(2, round($member['score'] / $peak * 100, 2)) : 0 }}%"></div>
                            </div>
                            <span class="w-11 shrink-0 text-right text-body font-extrabold tracking-[-0.02em] {{ $member['flagged'] ? 'text-danger-text' : '' }}">
                                {{ round($member['share']) }}%
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Detailed breakdown --}}
            <div class="flex flex-col gap-3">
                <h3 class="text-lead font-extrabold tracking-[-0.02em]">Detailed breakdown</h3>

                @php
                    $breakdownHeadings = [
                        'Member',
                        ...array_map(fn ($m) => [$m['label'], 'right'], array_values(ContributionReport::METRICS)),
                        ['Score', 'right'],
                        ['Share', 'right'],
                    ];
                @endphp

                <x-data-table :headings="$breakdownHeadings" min-width="720px">
                    @foreach ($members as $member)
                        <tr>
                            <td>
                                <x-table.person :name="$member['user']->name">
                                    @if ($member['flagged'])
                                        <x-table.dot-pill tone="danger">Free rider</x-table.dot-pill>
                                    @endif
                                </x-table.person>
                            </td>

                            @foreach (array_keys(ContributionReport::METRICS) as $key)
                                <td class="num text-body font-semibold text-ink-600">{{ $member['counts'][$key] }}</td>
                            @endforeach

                            <td>
                                <x-table.score :score="$member['score']" :unit="null"
                                               :fill="$member['score'] > 0 ? max(2, $member['score'] / $peak * 100) : 0"
                                               :flagged="$member['flagged']" class="ml-auto" />
                            </td>

                            <td class="num text-body font-extrabold {{ $member['flagged'] ? 'text-danger-text' : '' }}">
                                {{ round($member['share']) }}%
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>

                <p class="text-label text-ink-400">
                    Counts come from the activity log, not from the task or message tables — the log is what
                    the score is calculated from. “Doc edits” covers documents created and edited.
                </p>
            </div>

            {{-- Verdict --}}
            @if ($flagged->isNotEmpty())
                <div class="flex items-start gap-3.5 rounded-[20px] bg-danger-bg px-[18px] py-4">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-pill bg-danger-line text-danger-text">
                        <x-icon name="alert" size="17" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="text-body font-extrabold tracking-[-0.015em] text-danger-deep">
                            {{ $flagged->count() === 1 ? 'Free rider detected' : 'Free riders detected' }}
                        </span>
                        <span class="text-pretty text-meta leading-[1.5] text-danger-deep">
                            {{ $flagged->pluck('user.name')->join(', ', ' and ') }}
                            {{ $flagged->count() === 1 ? 'is' : 'are' }} below
                            {{ round($threshold, 1) }} points — {{ round($report['ratio'] * 100) }}% of the
                            {{ round($report['average'], 1) }} group average. Worth checking the board and chat
                            history before grading.
                        </span>
                    </div>
                </div>
            @elseif ($report['hasActivity'])
                <div class="flex items-start gap-3.5 rounded-[20px] bg-success-bg px-[18px] py-4">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-pill bg-success-text/20 text-success-text">
                        <x-icon name="check" size="17" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="text-body font-extrabold tracking-[-0.015em] text-success-text">
                            Contribution looks balanced
                        </span>
                        <span class="text-pretty text-meta leading-[1.5] text-success-text">
                            Every member is above {{ round($threshold, 1) }} points. No action needed before grading.
                        </span>
                    </div>
                </div>
            @endif
        </section>
    </div>

</x-layouts.app>
