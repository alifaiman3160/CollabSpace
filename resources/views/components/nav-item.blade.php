@props([
    'icon'  => 'circle',
    'label' => '',
    'route' => '#',
    'badge' => null,
    'live'  => false,   // render the live unread counter instead of a static badge
    'active' => null,
])

@php
    // Accepts a named route, a URL, or '#' while a screen is still unbuilt.
    $href = match (true) {
        \Illuminate\Support\Facades\Route::has($route) => route($route),
        str_starts_with($route, '/'), str_starts_with($route, 'http'), $route === '#' => $route,
        default => '#',
    };

    $isActive = $active ?? ($href !== '#' && request()->fullUrlIs($href, $href.'/*'));
@endphp

<a href="{{ $href }}"
   @if($isActive) aria-current="page" @endif
   {{ $attributes->class([
        'group flex items-center gap-3 rounded-pill px-[14px] py-[11px] text-nav transition-colors',
        'bg-ink text-white font-bold shadow-raised' => $isActive,
        'font-semibold text-ink/[.66] hover:bg-white/50 hover:text-ink' => ! $isActive,
   ]) }}>
    <x-icon :name="$icon" />
    <span class="flex-1 truncate">{{ $label }}</span>

    @if($live)
        {{-- Livewire, so acting on the notifications page updates this without a reload. --}}
        <livewire:notification-bell />
    @elseif(filled($badge))
        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-pill bg-danger px-1.5 text-tiny font-bold text-white">
            {{ $badge }}
        </span>
    @endif
</a>
