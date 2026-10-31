@props([
    'subject',
    // The dashboard lists several subjects at once and needs the code on each
    // card; the workspace is already inside one subject and does not.
    'showCode' => false,
])

{{--
    The lecturer's assignment, as the students see it.

    One component for both screens on purpose: the brief and the countdown must
    read identically wherever a student meets them, and two copies of this
    markup would start agreeing less with every edit.

    Renders nothing at all when the subject carries neither a brief nor a
    deadline — an empty panel saying "no brief" would be worse than silence,
    because a subject without one is the normal state early in a semester.
--}}
@if ($subject->hasAssignment())
    @php
        $days = $subject->daysToProjectDeadline();
        $passed = $subject->projectDeadlinePassed();
        // Inside a week is when it starts mattering; past it, it is an alarm.
        $urgent = $days !== null && ! $passed && $days <= 7;
    @endphp

    <section {{ $attributes->class([
                 'flex flex-col gap-3 rounded-panel border p-5',
                 'border-danger-line bg-danger-bg' => $passed,
                 'border-surface-strong bg-surface-soft' => ! $passed,
             ]) }}
             aria-label="Assignment brief">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="flex items-center gap-2">
                <x-icon name="book" size="16" class="shrink-0 text-ink-300" />
                <span class="text-label font-bold uppercase tracking-eyebrow text-ink-400">
                    Assignment
                    @if ($showCode)
                        · {{ $subject->code }}
                    @endif
                </span>
            </span>

            @if ($subject->project_deadline)
                <span class="flex flex-wrap items-center gap-2">
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
                </span>
            @endif
        </div>

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
    </section>
@endif
