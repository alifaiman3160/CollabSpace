@props([
    'variant' => 'primary',   // primary | secondary | icon
    'href'    => null,
    'type'    => 'button',
    'icon'    => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-pill text-nav font-bold transition-colors disabled:opacity-50 disabled:pointer-events-none';

    $variants = [
        'primary'   => 'bg-ink text-white px-5 py-3 hover:bg-ink-hover',
        'secondary' => 'bg-surface-muted text-ink px-5 py-3 hover:bg-surface-hover',
        'icon'      => 'bg-surface-muted text-ink size-11 hover:bg-surface-hover',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    {{ $attributes->class($classes) }}>
    @if($icon)
        <x-icon :name="$icon" />
    @endif
    {{ $slot }}
</{{ $tag }}>
