@props([
    'groups',      // every group the student belongs to
    'current',     // the one being looked at
    'route',       // route name to link each group to
])

{{--
    Picks which group's screen you are looking at.

    A student can sit in a group for several subjects at once, and every
    group-scoped screen needs the same control. It used to be written out
    separately on each of them, which is how three copies ended up differing:
    the chat named the group and marked the current tab for screen readers,
    while documents and the workspace showed only the subject code and marked
    nothing. The task board had no switcher at all, so a student in two subjects
    could only ever reach the board of whichever group came first.

    One component now, so a fourth screen cannot quietly go without one.

    Hidden entirely when there is only one group: a switcher offering a single
    choice is a dead control that asks to be clicked.
--}}
@if ($groups->count() > 1)
    <div class="flex flex-col gap-2">
        <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Your groups</span>

        <div class="flex flex-wrap gap-1 rounded-pill bg-surface-muted p-[3px]">
            @foreach ($groups as $g)
                @php($isCurrent = $g->is($current))
                <a href="{{ route($route, $g) }}"
                   title="{{ $g->subject->name }} · {{ $g->name }}"
                   @if ($isCurrent) aria-current="page" @endif
                   class="flex h-8 items-center gap-1.5 rounded-pill px-[15px] text-meta font-bold interactive
                          {{ $isCurrent ? 'bg-ink text-white' : 'text-ink-600 hover:bg-surface-hover hover:text-ink' }}">
                    {{ $g->subject->code }}
                    <span class="font-semibold {{ $isCurrent ? 'text-white/60' : 'text-ink-300' }}">
                        {{ $g->name }}
                    </span>
                </a>
            @endforeach
        </div>
    </div>
@endif
