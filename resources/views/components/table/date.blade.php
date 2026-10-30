@props([
    'date' => null,        // Carbon, or null
    'empty' => '—',   // a muted dash, never "Not set" or "None"
    'format' => 'j M Y',
    'done' => false,       // a finished item is never "overdue"
    'soonWithin' => 7,     // days; null turns the "due soon" pill off
])

@php
    $date = $date ? \Illuminate\Support\Carbon::parse($date) : null;
    $days = $date ? now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false) : null;
    $overdue = $date && ! $done && $days < 0;
    $soon = $date && ! $done && ! $overdue && $soonWithin !== null && $days <= $soonWithin;
@endphp

{{-- A muted date. A dot pill joins it only when it is overdue or close. --}}
<div {{ $attributes->class('flex flex-col items-start gap-1') }}>
    @if ($date)
        <span class="whitespace-nowrap text-meta font-medium tabular-nums text-ink-400" title="{{ $date->format('j F Y') }}">{{ $date->format($format) }}</span>

        @if ($overdue)
            <x-table.dot-pill tone="danger">Overdue</x-table.dot-pill>
        @elseif ($soon)
            <x-table.dot-pill tone="warning">{{ $days === 0 ? 'Due today' : 'Due in '.$days.' '.Str::plural('day', $days) }}</x-table.dot-pill>
        @endif
    @else
        <span class="text-meta text-ink-300">{{ $empty }}</span>
    @endif
</div>
