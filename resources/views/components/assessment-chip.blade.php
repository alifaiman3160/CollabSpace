@props([
    'assessment' => null,   // the Assessment
])

{{-- An assessment's title as a small chip, the quiet sibling of <x-section-chip> (same shape, neutral). --}}
@if ($assessment)
    <span {{ $attributes->class('inline-flex items-center gap-1 whitespace-nowrap rounded-pill border border-surface-strong bg-surface px-2 py-px text-label font-bold text-ink-600') }}
          data-assessment-chip data-tooltip="{{ $assessment->typeLabel() }}"><x-icon name="book" size="11" class="shrink-0" />{{ $assessment->title }}</span>
@endif
