<x-layouts.app title="Contribution reports — CollabSpace"
               heading="Contribution reports"
               subtitle="Who actually did the work — measured from board, chat and document activity.">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        @if ($groups->isEmpty())
            <x-empty-state
                icon="chart"
                title="No groups to report on"
                body="Contribution is measured per group. Create a subject and a group, then assign students to it."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Select group</h2>

                <div class="flex flex-wrap gap-3">
                    @foreach ($groups as $group)
                        @php
                            $summary = $summaries[$group->id];
                            // Short badge from the group name, per the design.
                            $short = Str::of($group->name)
                                ->matchAll('/\b([A-Za-z0-9])/')
                                ->take(2)->implode('') ?: 'G';
                        @endphp

                        <a href="{{ route('lecturer.reports.show', $group) }}"
                           class="flex min-w-[230px] flex-[1_1_240px] items-center gap-3.5 rounded-[22px] border p-4 transition-colors
                                  {{ $summary['flagged'] > 0 ? 'border-danger-line bg-danger-bg hover:bg-danger-line/60' : 'border-surface-line bg-surface shadow-subtle hover:bg-surface-soft' }}">

                            <span class="flex size-[42px] shrink-0 items-center justify-center rounded-[14px] text-body font-extrabold
                                         {{ $summary['flagged'] > 0 ? 'bg-white text-danger-text' : 'bg-periwinkle text-ink' }}">
                                {{ Str::upper($short) }}
                            </span>

                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate text-[15px] font-extrabold tracking-[-0.02em]">{{ $group->name }}</span>
                                <span class="truncate text-meta font-semibold text-ink-400">
                                    {{ $group->subject->code }} · {{ $group->subject->name }}
                                </span>
                                <span class="text-label font-semibold text-ink-300">
                                    {{ $group->members_count }} {{ Str::plural('member', $group->members_count) }} ·
                                    {{ $summary['total'] }} {{ Str::plural('point', $summary['total']) }}
                                </span>
                            </span>

                            @if ($summary['flagged'] > 0)
                                <x-badge tone="danger">
                                    {{ $summary['flagged'] }} flagged
                                </x-badge>
                            @endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

</x-layouts.app>
