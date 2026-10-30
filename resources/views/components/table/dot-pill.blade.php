@props([
    'tone' => 'neutral',   // success | warning | danger | neutral
])

@php
    $tones = [
        'success' => 'bg-success-bg text-success-text',
        'warning' => 'bg-warning-bg text-warning-text',
        'danger'  => 'bg-danger-bg text-danger-text',
        'neutral' => 'bg-surface-muted text-ink-600',
    ];
@endphp

{{-- A pill led by a status dot. The dot carries the colour; the words carry the meaning. --}}
<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-pill px-2.5 py-1 text-label font-bold',
    $tones[$tone] ?? $tones['neutral'],
]) }}>
    <span class="size-1.5 shrink-0 rounded-pill bg-current" aria-hidden="true"></span>
    {{ $slot }}
</span>
