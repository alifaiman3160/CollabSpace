@props([
    'group',                // needs: subject, members (names), members_count, tasks_total, tasks_done, tasks_overdue
    'href' => null,         // makes the group's name a link
    'variant' => 'full',    // full | light | contribution (members and the page's own content, no task progress)
    'i' => 0,
    'stretch' => false,     // the whole card is the link: it is focusable, with a ring, and its footer buttons stay on top
])

@php
    $colour = \App\Support\SubjectPalette::for($group->subject_id);
    $count = (int) $group->members_count;
    $size = (int) $group->group_size;
    $full = $size > 0 && $count >= $size;
@endphp

{{--
    A group as a pastel-tinted card, in its subject's colour. Name (with how it was formed), subject, and, in the full
    variant, the members and the task progress; the page's own content goes in the default slot and its actions in
    the footer slot. Cards in a grid are the same height: the body takes the space and the footer sits at the bottom.
--}}
<article {{ $attributes->class(['cs-card-in cs-lift flex min-w-0 flex-col rounded-panel border text-ink', 'relative focus-within:ring-2 focus-within:ring-ink/25' => $stretch]) }}
         style="--i: {{ min($i, 12) }}; background-color: {{ $colour['bg'] }}; border-color: {{ $colour['border'] }};"
         data-group-card-block data-palette="{{ $colour['name'] }}">
    <div class="flex flex-1 flex-col gap-4 p-5">
        <div class="flex flex-col gap-1.5">
            <div class="flex items-start justify-between gap-2">
                @if ($href)
                    <a href="{{ $href }}" class="interactive min-w-0 truncate rounded-md text-h3 font-extrabold tracking-title {{ $stretch ? 'after:absolute after:inset-0 after:rounded-panel after:content-[\'\']' : '' }}" title="{{ $group->name }}">{{ $group->name }}</a>
                @else
                    <h2 class="min-w-0 truncate text-h3 font-extrabold tracking-title" title="{{ $group->name }}">{{ $group->name }}</h2>
                @endif

                @isset($badge)
                    {{ $badge }}
                @elseif ($variant === 'full')
                    <span class="shrink-0 rounded-pill bg-white/70 px-2 py-0.5 text-label font-bold text-ink-600" title="{{ $group->formationLabel() }}" data-source>
                        {{ $group->formation_method === 'manual' ? 'Manual' : 'Auto' }}
                    </span>
                @endisset
            </div>

            <div class="flex min-w-0 items-center gap-2">
                <span class="shrink-0 rounded-pill px-2 py-0.5 text-label font-extrabold" style="background-color: {{ $colour['accent'] }};">{{ $group->subject->code }}</span>
                {{-- Only where the page loaded the section with its groups (no query per card). --}}
                @if ($group->relationLoaded('section'))<x-section-chip :section="$group->section" :code="$group->subject->code" class="shrink-0" />@endif
                <span class="truncate text-label font-semibold text-ink-600" title="{{ $group->subject->name }}">{{ $group->subject->name }}</span>
                @isset($chip)<span class="ml-auto shrink-0">{{ $chip }}</span>@endisset
            </div>
        </div>

        @if ($variant !== 'light')
            <div class="flex items-center gap-2.5" data-group-members>
                @if ($count > 0)
                    <x-table.avatar-stack :names="$group->members->pluck('name')" :leader="$group->members->firstWhere('id', $group->leader_id)?->name" />
                @endif
                <span class="text-meta font-semibold tabular-nums">{{ $count }}/{{ $size }}</span>
                @if ($variant === 'full')
                    @if ($full)
                        <x-table.dot-pill tone="success" data-tooltip="The group has all its members">Full</x-table.dot-pill>
                    @else
                        <x-table.dot-pill tone="neutral">Open</x-table.dot-pill>
                    @endif
                @endif
            </div>
        @endif

        @if ($variant === 'full')
            <div class="mt-auto rounded-card bg-white/60 p-3" data-group-progress>
                <x-table.progress :done="$group->tasks_done" :total="$group->tasks_total" :overdue="$group->tasks_overdue" class="!min-w-0" />
            </div>
        @endif

        {{ $slot }}
    </div>

    @isset($footer)
        <div class="{{ $stretch ? 'relative z-[1] ' : '' }}flex flex-wrap items-center justify-between gap-1.5 border-t px-3 py-3" style="border-color: {{ $colour['border'] }};">
            {{ $footer }}
        </div>
    @endisset
</article>