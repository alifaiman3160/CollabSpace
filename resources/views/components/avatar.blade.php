@props([
    'name'  => '',
    'size'  => 'md',   // sm | md | lg
    // Per-sender colour classes from App\Support\MemberColour. Null keeps the
    // default periwinkle, which is what every screen outside chat uses.
    'tone'  => null,
])

@php
    $initials = collect(preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: '?';

    $sizes = [
        'sm' => 'size-8 text-label',
        'md' => 'size-10 text-meta',
        'lg' => 'size-14 text-nav',
    ];
@endphp

<span {{ $attributes->class([
        'inline-flex items-center justify-center rounded-pill font-bold select-none',
        'bg-periwinkle text-ink' => $tone === null,
        $tone => $tone !== null,
        $sizes[$size] ?? $sizes['md'],
   ]) }}
   title="{{ $name }}">
    {{ $initials }}
</span>
