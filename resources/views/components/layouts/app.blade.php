@props([
    'title'     => 'CollabSpace',
    'heading'   => null,
    'subtitle'  => null,
    // A detail screen passes these to get a back link above the heading, where
    // the eye actually is. The header actions sit on the far right, which is
    // too easy to miss when you are deep in a form.
    'back'      => null,
    'backLabel' => 'Back',
    // A screen that is one fixed-height workspace (the group chat) asks for this: from 900px up the page is
    // exactly as tall as the screen and does not scroll, and the content is a flex chain that hands the
    // remaining height to the screen's own panels. Every other screen keeps the normal scrolling page.
    'fit' => false,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-soft text-ink {{ $fit ? 'min-h-screen min-[900px]:h-dvh min-[900px]:overflow-hidden' : 'min-h-screen' }}">
<div class="flex {{ $fit ? 'min-h-screen min-[900px]:h-dvh min-[900px]:overflow-hidden' : 'min-h-screen' }}">

    {{-- Sidebar: 222px, sticky, periwinkle, rounded on the right edge only.
         x-persist keeps this very element across wire:navigate visits, so the sidebar is neither
         re-rendered nor re-animated; the highlighted item follows the address (wire:current on the links).
         Put on the <aside> itself rather than a @persist wrapper, which would add a div and end the sticky. --}}
    <aside x-persist="sidebar" class="sticky top-0 flex h-dvh min-h-0 w-sidebar shrink-0 self-start flex-col bg-periwinkle px-[14px] pb-[18px] pt-[22px] [border-radius:0_26px_26px_0]">

        <a href="{{ url('/') }}" wire:navigate class="mb-6 flex items-center gap-2.5 px-[14px]">
            <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-pill bg-ink text-white">
                <x-icon name="grid" size="16" />
            </span>
            <span class="flex min-w-0 flex-col">
                <span class="text-lead font-extrabold leading-tight tracking-title">CollabSpace</span>
                <span class="truncate text-micro font-semibold text-ink/[.48]">{{ $roleLabel ?? 'Student Portal' }}</span>
            </span>
        </a>

        <nav class="flex flex-1 flex-col gap-1 overflow-y-auto" aria-label="Main">
            @foreach($nav ?? [] as $item)
                <x-nav-item
                    :icon="$item['icon']"
                    :label="$item['label']"
                    :route="$item['route']"
                    :badge="$item['badge'] ?? null"
                    :live="$item['live'] ?? false" />
            @endforeach
        </nav>

        {{-- User block --}}
        <div class="mt-4 flex items-center gap-3 rounded-card bg-white/50 p-3">
            <x-avatar :name="auth()->user()->name ?? 'Guest User'" size="sm" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-nav font-bold">{{ auth()->user()->name ?? 'Guest User' }}</p>
                <p class="truncate text-tiny font-semibold text-ink/[.5]">{{ auth()->user()->email ?? '' }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex text-ink/[.55] interactive hover:text-ink" aria-label="Log out">
                    <x-icon name="logout" />
                </button>
            </form>
        </div>
    </aside>

    {{-- Content --}}
    {{-- Soft page background: white cards are defined by contrast against it,
         which is what makes them read as cards at all. --}}
    <div data-page-content class="cs-page-content min-w-0 flex-1 bg-surface-soft {{ $fit ? 'min-[900px]:flex min-[900px]:min-h-0 min-[900px]:flex-col' : '' }}">
        <header class="flex flex-wrap items-center gap-4 px-7 pb-[18px] pt-6 {{ $fit ? 'shrink-0' : '' }}">
            <div class="min-w-0 flex-1">
                @if($heading)
                    @isset($breadcrumb)
                        {{ $breadcrumb }}
                    @endisset
                    @if($back)
                        <a href="{{ $back }}"
                           class="mb-1.5 inline-flex w-fit items-center gap-1.5 text-meta font-bold text-ink-400 transition-colors hover:text-ink">
                            <x-icon name="arrow-left" size="15" />
                            {{ $backLabel }}
                        </a>
                    @endif
                    <h1 class="text-h1 font-extrabold tracking-title">{{ $heading }}</h1>
                @endif
                @if($subtitle)
                    <p class="mt-1 text-meta font-medium text-ink-400">{{ $subtitle }}</p>
                @endif
            </div>

            {{-- There is no global search here. A header search box on every
                 screen invites people to type into it, and there is nothing
                 behind it to answer — searching "task" from the account page
                 has no meaning. The two screens with something worth searching,
                 Manage users and Students, carry their own field that filters
                 the list they show. --}}
            @isset($actions)
                <div class="flex items-center gap-3">
                    {{ $actions }}
                </div>
            @endisset
        </header>

        <main class="px-7 {{ $fit ? 'flex flex-col pb-5 min-[900px]:min-h-0 min-[900px]:flex-1' : 'pb-12' }}">
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Mounted once here rather than per screen, so every action anywhere in the
     app reports itself. Six screens previously forgot to include the banner,
     which meant those actions appeared to do nothing at all. --}}
<x-toast />

{{-- Livewire owns Alpine for the whole app: it bundles and starts its own
     copy, so loading a second one from app.js would run two instances on any
     page carrying a Livewire component. Loaded on every page so the Alpine
     directives on the non-Livewire screens work too. --}}
@livewireScripts
</body>
</html>
