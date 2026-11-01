@props([
    'icon'  => 'circle',
    'label' => '',
    'route' => '#',
    'badge' => null,
    'live'  => false,   // render the live unread counter instead of a static badge
    'active' => null,
    // An item that opens into the person's groups: its key (what the open state is remembered under) and the rows.
    // Each row is [label, suffix, href, match, dot, all?]. Without rows this is a plain link.
    'group' => null,
    'children' => [],
    'open' => false,
    'collapsed' => false,
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
    $onItem = $href !== '#' && $path !== '' && request()->is($path, $path.'/*');

    $expandable = $group !== null && count($children) > 0;

    // An item that opens stays the black pill on every page under it (the list page often sends you straight to a
    // group's page); on a page of one of its groups that group's row is also lit, softly.
    $activeChild = $expandable ? \App\Support\SidebarState::activeChild(request()->path(), $children) : null;
    $isActive = $expandable ? $onItem : ($active ?? $onItem);
    $isOpen = $expandable && ($open || $activeChild !== null);

    $linkClasses = [
        'cs-navlink group relative flex items-center gap-3 rounded-pill px-[14px] py-[11px] text-nav motion-colors',
        'font-semibold text-ink/[.66] hover:bg-white/50 hover:text-ink',
        'data-current:bg-ink data-current:text-white data-current:font-bold data-current:shadow-raised data-current:hover:bg-ink data-current:hover:text-white',
        'pr-8' => $expandable,
    ];
@endphp

@if ($expandable)
    <div class="cs-navgroup relative" data-nav-group="{{ $group }}"
         x-on:mouseenter="hoverIn('{{ $group }}')" x-on:mouseleave="hoverOut()" x-on:focusout="focusLeft($event)">
        {{-- Moved by the sidebar script, not wire:current: a page of one group must light that group's row, not this item. --}}
        <a href="{{ $href }}" wire:navigate data-nav-link data-match="/{{ $path }}"
           data-rail-tip="{{ $label }}"
           @if ($collapsed) aria-label="{{ $label }}" @endif
           @if ($isActive) aria-current="{{ $activeChild !== null ? 'true' : 'page' }}" data-current @endif
           @if ($activeChild !== null) data-parent-current @endif
           x-on:keydown="railKey($event, '{{ $group }}')"
           {{ $attributes->class($linkClasses) }}>
            <x-icon :name="$icon" class="shrink-0" />
            <span class="cs-label flex-1 truncate">{{ $label }}</span>
        </a>

        <button type="button"
                class="cs-chev absolute right-1.5 top-[9px] flex size-6 items-center justify-center rounded-pill text-ink/[.55] interactive hover:bg-white/60 hover:text-ink"
                aria-label="{{ $label }} groups" aria-controls="nav-sub-{{ $group }}"
                aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                x-bind:aria-expanded="isOpen('{{ $group }}') ? 'true' : 'false'"
                x-on:click="toggleSection('{{ $group }}')">
            <x-icon name="chevron" size="15" class="cs-chevron cs-chev__icon" />
        </button>

        <div id="nav-sub-{{ $group }}" class="cs-subnav {{ $isOpen ? 'is-open' : '' }}"
             x-bind:class="{ 'is-open': shown('{{ $group }}') }"
             @unless ($isOpen) inert @endunless x-bind:inert="! shown('{{ $group }}')"
             x-on:keydown="flyoutKey($event, '{{ $group }}')">
            <div class="cs-subnav__inner">
                <a href="{{ $href }}" wire:navigate class="cs-fly-title" tabindex="{{ $isOpen ? '0' : '-1' }}">{{ $label }}</a>
                <ul class="cs-sublist">
                    @foreach ($children as $i => $child)
                        <li>
                            <a href="{{ $child['href'] }}" wire:navigate data-child
                               @if (! empty($child['match'])) data-match="{{ $child['match'] }}" @endif
                               @if ($i === $activeChild) aria-current="page" data-current @endif
                               @if (! empty($child['all'])) data-all @endif
                               data-tooltip="{{ $child['label'] }}{{ ! empty($child['suffix']) ? ' · '.$child['suffix'] : '' }}" data-tooltip-side="right"
                               class="cs-sublink flex items-center gap-2.5 rounded-pill py-[7px] pl-3 pr-3 text-meta font-semibold text-ink/[.62] motion-colors hover:bg-white/50 hover:text-ink data-current:bg-white/75 data-current:font-bold data-current:text-ink">
                                @if (! empty($child['dot']))
                                    <span class="size-2 shrink-0 rounded-pill" style="background-color: {{ $child['dot'] }};" aria-hidden="true"></span>
                                @endif
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate">{{ $child['label'] }}</span>
                                    @if (! empty($child['suffix']))
                                        <span class="truncate text-tiny font-medium leading-tight opacity-60">{{ $child['suffix'] }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@else
    {{-- The highlight is drawn from data-current (and aria-current), not from classes chosen here, because the
         sidebar is kept across wire:navigate visits: wire:current moves the attribute when the address changes,
         and the page script mirrors it onto aria-current. Rendered here too, so the first paint is right. --}}
    <a href="{{ $href }}"
       data-nav-link data-rail-tip="{{ $label }}"
       @if ($collapsed) aria-label="{{ $label }}" data-tooltip="{{ $label }}" data-tooltip-side="right" @endif
       @if($isActive) aria-current="page" data-current @endif
       @if($href !== '#') wire:navigate wire:current="is-current" @endif
       {{ $attributes->class($linkClasses) }}>
        <x-icon :name="$icon" class="shrink-0" />
        <span class="cs-label flex-1 truncate">{{ $label }}</span>

        @if($live)
            {{-- Livewire, so acting on the notifications page updates this without a reload. --}}
            <span class="cs-nav-badge"><livewire:notification-bell /></span>
        @elseif(filled($badge))
            <span class="cs-nav-badge"><span class="inline-flex h-5 min-w-5 items-center justify-center rounded-pill bg-danger px-1.5 text-tiny font-bold text-white">
                {{ $badge }}
            </span></span>
        @endif
    </a>
@endif
