@props([
    'assessments',          // the subject's Assessment models
    'current' => null,      // the chosen assessment id
    'route',                // the page's own route name
    'params' => [],         // the page's route parameters
])

{{--
    "Assessment", then one chip per assessment of one subject (default: the active one). For the pages that are already
    about one subject or one group; the lists use the same row inside <x-subject-section-filter>. It appears only when the
    subject has two or more assessments. The choice lives in the query string only; everything else in the address is kept.
--}}
@if ($assessments->count() > 1)
    @php($keep = request()->except('assessment', 'page'))
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by assessment" data-assessment-filter>
        <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Assessment</span>
        @foreach ($assessments as $option)
            @php($on = $current === $option->id)
            <a href="{{ route($route, $params + $keep + ['assessment' => $option->id]) }}" wire:navigate
               @if ($on) aria-current="page" @endif data-assessment-option="{{ $option->id }}"
               data-tooltip="{{ $option->typeLabel() }}"
               class="interactive flex h-9 items-center rounded-pill border px-4 text-meta font-bold motion-colors
                      {{ $on ? 'border-ink bg-ink text-white' : 'border-surface-line bg-surface text-ink-600 hover:bg-surface-hover hover:text-ink' }}">{{ $option->title }}</a>
        @endforeach
    </div>
@endif
