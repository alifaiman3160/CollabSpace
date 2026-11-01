@props([
    'subject',
    // Shows the subject code in the header, for a screen that lists several subjects at once; the group pages are
    // already inside one subject and do not need it.
    'showCode' => false,
])

{{--
    The lecturer's project brief, as the students see it, on the pages of the group it is for (workspace and task
    board). A card that starts closed: its header carries the title and the due date with the countdown, and opening it
    (the shared .cs-collapse motion) reads the brief.

    One component for both pages on purpose: the brief and the countdown must read identically wherever a student meets
    them. Renders nothing at all when the subject carries neither a brief nor a deadline: a subject without one is the
    normal state early in a semester, and an empty card saying "no brief" would be worse than silence.
--}}
@if ($subject->hasAssignment())
    @php
        $days = $subject->daysToProjectDeadline();
        $passed = $subject->projectDeadlinePassed();
        // Inside a week is when it starts mattering; past it, it is an alarm.
        $urgent = $days !== null && ! $passed && $days <= 7;
    @endphp

    <section {{ $attributes->class([
                 'flex flex-col rounded-panel border p-5',
                 'border-danger-line bg-danger-bg' => $passed,
                 'border-surface-strong bg-surface-soft' => ! $passed,
             ]) }}
             x-data="{ open: false }" aria-label="Project brief" data-project-brief>

        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                aria-controls="project-brief-{{ $subject->id }}"
                class="interactive flex w-full flex-wrap items-center justify-between gap-3 rounded-md text-left">
            <span class="flex items-center gap-2">
                <x-icon name="book" size="16" class="shrink-0 text-ink-300" />
                <span class="text-label font-bold uppercase tracking-eyebrow text-ink-400">
                    Project brief
                    @if ($showCode)
                        · {{ $subject->code }}
                    @endif
                </span>
            </span>

            <span class="flex flex-wrap items-center gap-2">
                @if ($subject->project_deadline)
                    <span class="text-meta font-semibold text-ink-400">
                        Group project due {{ $subject->project_deadline->format('j F Y') }}
                    </span>
                    <span @class([
                            'rounded-pill px-3 py-1.5 text-label font-bold',
                            'bg-danger text-white' => $passed,
                            'bg-ink text-white' => ! $passed && $urgent,
                            'bg-surface-muted text-ink-600' => ! $passed && ! $urgent,
                          ])>
                        {{ $subject->projectDeadlineLabel() }}
                    </span>
                @endif
                <svg class="cs-chevron size-4 shrink-0 text-ink-400" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </span>
        </button>

        <x-collapse open="open" id="project-brief-{{ $subject->id }}" data-project-brief-body>
            <div class="pt-3">
                @if (filled($subject->brief))
                    {{-- Plain text, escaped, with the lecturer's own line breaks kept.
                         Not rich text: this is typed into a textarea, and running it
                         through the document sanitiser would imply markup is supported
                         when none of it survives. --}}
                    <p class="whitespace-pre-line text-body leading-[1.6] text-ink-600">{{ $subject->brief }}</p>
                @elseif ($subject->project_deadline)
                    <p class="text-meta text-ink-400">
                        Your lecturer has set the deadline but not yet written the brief.
                    </p>
                @endif
            </div>
        </x-collapse>
    </section>
@endif
