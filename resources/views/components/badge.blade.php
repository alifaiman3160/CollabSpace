@props([
    'tone' => 'neutral',   // danger | success | neutral | info | dark
])

@php
    $tones = [
        'danger'  => 'bg-danger-bg text-danger-text',
        'success' => 'bg-success-bg text-success-text',
        'neutral' => 'bg-surface-muted text-ink-600',
        'info'    => 'bg-periwinkle-soft text-periwinkle-link',
        'dark'    => 'bg-ink text-white',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-pill px-2.5 py-1 text-label font-bold whitespace-nowrap',
    $tones[$tone] ?? $tones['neutral'],
]) }}>
    {{ $slot }}
</span>
