@props([
    'variant' => 'primary',   // primary | secondary | icon
    'href'    => null,
    'type'    => 'button',
    'icon'    => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-pill text-nav font-bold btn-motion disabled:opacity-50 disabled:pointer-events-none';

    $variants = [
        'primary'   => 'bg-ink text-white px-5 py-3 hover:bg-ink-hover hover:shadow-button-hover',
        'secondary' => 'bg-surface-muted text-ink px-5 py-3 hover:bg-surface-hover hover:shadow-lift',
        'icon'      => 'bg-surface-muted text-ink size-11 hover:bg-surface-hover hover:shadow-lift',
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
