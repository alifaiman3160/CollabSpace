@props([
    'subject',
    // Shows the subject code in the header, for a screen that lists several subjects at once; the group pages are
    // already inside one subject and do not need it.
    'showCode' => false,
])

{{--
    The lecturer's brief for an assessment, as the students see it, on the pages of the group it is for (workspace and
    task board). A card that starts closed: its header carries the assessment's title, its due date with the week and the
    countdown; opening it (the shared .cs-collapse motion) reads the brief and lists the files. A subject with several
    assessments adds a row of chips to read the others (?assessment=, default the active one; a foreign id is ignored).
    Files for the whole subject show under every assessment.

    One component for both pages on purpose: the brief and the countdown must read identically wherever a student meets
    them. Renders nothing at all when the subject carries nothing to read: a subject without a brief is the normal state
    early in a semester, and an empty card saying "no brief" would be worse than silence.
--}}
@php
    $assessment = \App\Support\Sections\SubjectSectionFilter::assessmentFor($subject, request()->query('assessment'));
    $all = $subject->assessments;
    $several = $all->count() > 1;

    $everything = $subject->relationLoaded('files') ? $subject->files : collect();
    // This assessment's files, and the whole-subject ones. (A subject with no assessments shows all of its files.)
    $files = $everything->filter(fn ($f) => $f->reaches($assessment))->values();

    $brief = $subject->briefOf($assessment);
    $due = $subject->dueOf($assessment);
    $anything = $subject->hasAssignment() || $everything->isNotEmpty() || $all->contains(fn ($a) => filled($a->brief) || $a->due_on !== null);
@endphp

@if ($anything)
    @php
        $days = \App\Models\Subject::daysUntil($due);
        $passed = $days !== null && $days < 0;
        // Inside a week is when it starts mattering; past it, it is an alarm.
        $urgent = $days !== null && ! $passed && $days <= 7;
        $title = ($assessment?->title ?? 'Project').' brief';
        $reading = request()->has('assessment');
    @endphp

    <section {{ $attributes->class([
                 'flex flex-col rounded-panel border p-5',
                 'border-danger-line bg-danger-bg' => $passed,
                 'border-surface-strong bg-surface-soft' => ! $passed,
             ]) }}
             x-data="{ open: {{ $reading ? 'true' : 'false' }}, instant: {{ $reading ? 'true' : 'false' }} }" aria-label="{{ $title }}" data-project-brief>

        <button type="button" x-on:click="open = ! open; instant = false" x-bind:aria-expanded="open"
                aria-controls="project-brief-{{ $subject->id }}"
                class="interactive flex w-full flex-wrap items-center justify-between gap-3 rounded-md text-left">
            <span class="flex items-center gap-2">
                <x-icon name="book" size="16" class="shrink-0 text-ink-300" />
                <span class="text-label font-bold uppercase tracking-eyebrow text-ink-400" data-brief-title>
                    {{ $title }}
                    @if ($showCode)
                        · {{ $subject->code }}
                    @endif
                </span>
            </span>

            <span class="flex flex-wrap items-center gap-2">
                @if ($due)
                    <span class="text-meta font-semibold text-ink-400">
                        Group project due {{ $due->format('j F Y') }}@if ($dueWeek = $subject->weekOf($due)) · Week {{ $dueWeek }}@endif
                    </span>
                    <span @class([
                            'rounded-pill px-3 py-1.5 text-label font-bold',
                            'bg-danger text-white' => $passed,
                            'bg-ink text-white' => ! $passed && $urgent,
                            'bg-surface-muted text-ink-600' => ! $passed && ! $urgent,
                          ])>
                        {{ \App\Models\Subject::countdownLabel($days) }}
                    </span>
                @endif
                <svg class="cs-chevron size-4 shrink-0 text-ink-400" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </span>
        </button>

        <x-collapse open="open" instant="instant" id="project-brief-{{ $subject->id }}" data-project-brief-body>
            <div class="flex flex-col gap-3 pt-3">
                @if ($several)
                    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Read another assessment" data-brief-switcher>
                        @foreach ($all as $option)
                            @php
                                $closed = $option->due_on !== null && $option->due_on->copy()->startOfDay()->lt(now()->startOfDay());
                                $opens = ! $closed && $option->starts_on !== null && $option->starts_on->copy()->startOfDay()->gt(now()->startOfDay());
                                $on = $assessment?->id === $option->id;
                            @endphp
                            <a href="{{ url()->current() }}?assessment={{ $option->id }}" wire:navigate data-brief-option="{{ $option->id }}"
                               @if ($on) aria-current="page" @endif
                               class="interactive flex h-9 items-center gap-2 rounded-pill border px-3.5 text-meta font-bold motion-colors {{ $on ? 'border-ink bg-ink text-white' : 'border-surface-line bg-surface text-ink-600 hover:bg-surface-hover hover:text-ink' }}">
                                {{ $option->title }}
                                @if ($closed)
                                    <span class="text-label font-semibold {{ $on ? 'text-white/70' : 'text-ink-400' }}" data-brief-state>Closed</span>
                                @elseif ($opens)
                                    <span class="text-label font-semibold {{ $on ? 'text-white/70' : 'text-ink-400' }}" data-brief-state>Opens {{ $option->starts_on->format('j M') }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif

                <div>
                    @if (filled($brief))
                        {{-- Plain text, escaped, with the lecturer's own line breaks kept.
                             Not rich text: this is typed into a textarea, and running it
                             through the document sanitiser would imply markup is supported
                             when none of it survives. --}}
                        <p class="whitespace-pre-line text-body leading-[1.6] text-ink-600">{{ $brief }}</p>
                    @elseif ($due)
                        <p class="text-meta text-ink-400">
                            Your lecturer has set the deadline but not yet written the brief.
                        </p>
                    @else
                        <p class="text-meta text-ink-400">Your lecturer has not written this brief yet.</p>
                    @endif

                    @if ($files->isNotEmpty())
                        <ul class="mt-3 flex flex-col gap-2" aria-label="Files" data-brief-files>
                            @foreach ($files as $file)
                                <x-subject-file-row :file="$file" :scope="$file->assessment_id === null && $several ? 'Course materials' : null" />
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </x-collapse>
    </section>
@endif
