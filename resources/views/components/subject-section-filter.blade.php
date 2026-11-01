@props([
    'filter',               // from SubjectSectionFilter::for()
    'route',                // the page's own route name
    'params' => [],         // the page's route parameters
])

{{--
    One filter block for the lecturer's list pages, directly under the page title and above any figures.
    Row 1: "Subject", then All subjects and one chip per subject (a dropdown when there are more than six).
    Row 2: "Section", then All sections and one chip per section. It exists only while exactly one subject is chosen
    and that subject has two or more sections, so there are never combined "SUBJECT · Section" chips.
    Choosing another subject drops the section (and a group picked from the Students search); choosing a section keeps
    the subject. Everything else in the address (search, ...) is kept. The choice lives in the query string only.
--}}
@php
    $subjects = $filter['subjects'];
    $subjectId = $filter['subjectId'];
    $sections = $filter['sections'];
    $sectionId = $filter['sectionId'];

    $whenSubjectChanges = request()->except('subject', 'section', 'group', 'page');
    $whenSectionChanges = request()->except('section', 'page');
    $pill = 'interactive flex h-9 items-center rounded-pill border px-4 text-meta font-bold motion-colors';
    $off = 'border-surface-line bg-surface text-ink-600 hover:bg-surface-hover hover:text-ink';
    $label = 'text-label font-bold uppercase tracking-eyebrow text-ink/[.55]';
@endphp

@if ($subjects->isNotEmpty())
    <div class="flex flex-col gap-3" data-filter-block>
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by subject" data-subject-filter>
            <span class="{{ $label }}">Subject</span>

            @if ($subjects->count() > \App\Support\Sections\SubjectSectionFilter::CHIPS_UP_TO)
                <select aria-label="Subject" data-subject-select
                        x-data x-on:change="window.Livewire ? window.Livewire.navigate($event.target.value) : (window.location = $event.target.value)"
                        class="h-9 max-w-xs rounded-pill border border-surface-line bg-surface px-4 text-meta font-bold text-ink-600 focus:outline-none focus:ring-2 focus:ring-ink/10">
                    <option value="{{ route($route, $params + $whenSubjectChanges) }}" @selected($subjectId === null)>All subjects</option>
                    @foreach ($subjects as $subject)
                        <option value="{{ route($route, $params + $whenSubjectChanges + ['subject' => $subject->id]) }}" @selected($subjectId === $subject->id)>{{ $subject->code }} — {{ $subject->name }}</option>
                    @endforeach
                </select>
            @else
                <a href="{{ route($route, $params + $whenSubjectChanges) }}" wire:navigate
                   @if ($subjectId === null) aria-current="page" @endif
                   class="{{ $pill }} {{ $subjectId === null ? 'border-ink bg-ink text-white' : $off }}">All subjects</a>

                @foreach ($subjects as $subject)
                    @php($colour = \App\Support\SubjectPalette::for($subject->id))
                    @php($on = $subjectId === $subject->id)
                    <a href="{{ route($route, $params + $whenSubjectChanges + ['subject' => $subject->id]) }}" wire:navigate
                       title="{{ $subject->name }}" data-palette="{{ $colour['name'] }}"
                       @if ($on) aria-current="page" @endif
                       class="{{ $pill }} text-ink"
                       style="background-color: {{ $on ? $colour['accent'] : $colour['bg'] }}; border-color: {{ $on ? $colour['accent'] : $colour['border'] }};">{{ $subject->code }}</a>
                @endforeach
            @endif
        </div>

        @if ($sections->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by section" data-section-filter>
                <span class="{{ $label }}">Section</span>
                <a href="{{ route($route, $params + $whenSectionChanges) }}" wire:navigate
                   @if ($sectionId === null) aria-current="page" @endif
                   class="{{ $pill }} {{ $sectionId === null ? 'border-ink bg-ink text-white' : $off }}">All sections</a>
                @foreach ($sections as $option)
                    @php($on = $sectionId === $option->id)
                    <a href="{{ route($route, $params + $whenSectionChanges + ['section' => $option->id]) }}" wire:navigate
                       @if ($on) aria-current="page" @endif data-section-option="{{ $option->id }}"
                       data-tooltip="Section {{ $option->name }} of {{ $option->subject->code }}"
                       class="{{ $pill }} {{ $on ? 'border-ink bg-ink text-white' : $off }}">Section {{ $option->name }}</a>
                @endforeach
            </div>
        @endif
    </div>
@endif
