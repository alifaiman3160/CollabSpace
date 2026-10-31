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

    // Matched on the path alone. fullUrlIs() compared the query string too, so
    // Groups lost its highlight on /lecturer/groups?subject=3 (a subject filter)
    // and every other screen reached with ?page=, ?search= or ?role= did the same.
    $path = trim((string) parse_url($href, PHP_URL_PATH), '/');
    $isActive = $active ?? ($href !== '#' && $path !== '' && request()->is($path, $path.'/*'));
@endphp

{{-- The highlight is drawn from data-current (and aria-current), not from classes chosen here, because the
     sidebar is kept across wire:navigate visits: wire:current moves the attribute when the address changes,
     and the page script mirrors it onto aria-current. Rendered here too, so the first paint is right. --}}
<a href="{{ $href }}"
   @if($isActive) aria-current="page" data-current @endif
   @if($href !== '#') wire:navigate wire:current="is-current" @endif
   {{ $attributes->class([
        'group flex items-center gap-3 rounded-pill px-[14px] py-[11px] text-nav motion-colors',
        'font-semibold text-ink/[.66] hover:bg-white/50 hover:text-ink',
        'data-current:bg-ink data-current:text-white data-current:font-bold data-current:shadow-raised data-current:hover:bg-ink data-current:hover:text-white',
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
