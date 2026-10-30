@props([
    'score' => 0,        // the figure shown
    'fill'  => 0,        // 0-100: how far along the bar runs
    'unit'  => 'pts',
    'flagged' => false,  // paints the figure and bar red
])

@php
    $fill = max(0, min(100, (float) $fill));
@endphp

{{--
    A figure with a thin bar beside it, right-aligned in tabular digits so a
    column of them reads as a column. The slot is the muted line underneath.
--}}
<div {{ $attributes->class('flex flex-col items-end gap-1') }}>
    <div class="flex items-center gap-2.5">
        <span class="block h-1.5 w-16 overflow-hidden rounded-pill bg-surface-muted" aria-hidden="true">
            <span class="block h-full rounded-pill {{ $flagged ? 'bg-danger' : 'bg-ink' }}" style="width: {{ $fill }}%"></span>
        </span>
        <span class="min-w-[3.5rem] text-right text-nav font-bold tabular-nums {{ $flagged ? 'text-danger-text' : '' }}">
            {{ $score }}@if ($unit) {{ $unit }}@endif
        </span>
    </div>

    @if (trim((string) $slot) !== '')
        <span class="flex flex-wrap items-center justify-end gap-x-2 gap-y-1 text-label font-medium text-ink-400 tabular-nums">{{ $slot }}</span>
    @endif
</div>
