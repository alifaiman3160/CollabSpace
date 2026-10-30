@props(['title' => 'CollabSpace', 'intro' => false])

@php
    // The opening animation plays on the sign-in page only, and not when the page comes
    // back with a validation error or a status message (a reset link was sent, a password
    // was changed): those readers want the message, not a show.
    $play = $intro && ! $errors->any() && ! session('status');
@endphp

{{--
    Auth shell, from docs/design/CollabSpace Sign In.dc.html.

    Two panes inside one 32px card on a periwinkle field: the form on the left,
    a fixed marketing panel on the right that collapses away under 900px.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-periwinkle">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @if ($play)
        {{-- Marks the page as animating before first paint, unless this session has seen it or
             motion is reduced. Nothing is hidden without this class, so no script means no hiding. --}}
        <script>!function(d){try{if(sessionStorage.getItem('csIntro'))return}catch(e){}matchMedia('(prefers-reduced-motion:reduce)').matches||(d.className+=' js-intro')}(document.documentElement)</script>
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh overflow-x-clip bg-periwinkle text-ink antialiased">

@if ($play)
    {{-- Opening reveal. Decorative: hidden from assistive tech and inert, and it removes itself. --}}
    <div class="cs-preloader" aria-hidden="true" inert x-data="loginIntro">
        <div class="cs-preloader__logo">
            <span class="cs-preloader__tile">
                <svg width="30" height="30" viewBox="0 0 20 20" aria-hidden="true">
                    <rect class="cs-bar" style="--b:0" x="3.2" y="4" width="3.4" height="12" rx="1.3" fill="#c3cff9" />
                    <rect class="cs-bar" style="--b:1" x="8.3" y="4" width="3.4" height="8" rx="1.3" fill="#ffffff" />
                    <rect class="cs-bar" style="--b:2" x="13.4" y="4" width="3.4" height="10" rx="1.3" fill="#8fa4f0" />
                </svg>
            </span>
            <span class="cs-preloader__word text-[26px] font-extrabold tracking-title">CollabSpace</span>
        </div>
    </div>
@endif

@if ($intro)
    {{-- Living backdrop, sign-in page only. Decorative: behind everything (z-0, the content is z-10),
         never receives pointer or keyboard focus, and is hidden from assistive technology. --}}
    <div class="cs-bg" aria-hidden="true" inert x-data="loginBackdrop">
        {{-- Pattern: a dot grid and three faint kanban columns, drawn in SVG (no image file). If it
             does not draw, the periwinkle page and the blobs are all that is left. --}}
        <div class="cs-pattern">
            <svg width="100%" height="100%" focusable="false" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="cs-dots" width="28" height="28" patternUnits="userSpaceOnUse">
                        <circle cx="2" cy="2" r="1.4" fill="#8fa4f0" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#cs-dots)" opacity=".14" />
                <g class="cs-cols" fill="none" stroke="#8fa4f0" stroke-width="1.5" opacity=".13">
                    <rect x="4%" y="8%" width="28%" height="84%" rx="22" />
                    <rect x="36%" y="8%" width="28%" height="84%" rx="22" />
                    <rect x="68%" y="8%" width="28%" height="84%" rx="22" />
                    <line x1="4%" y1="16%" x2="32%" y2="16%" /><line x1="36%" y1="16%" x2="64%" y2="16%" /><line x1="68%" y1="16%" x2="96%" y2="16%" />
                </g>
            </svg>
        </div>

        <div class="cs-bg__layer" style="--d:0.4">
            <span class="cs-blob cs-blob--1"></span>
            <span class="cs-blob cs-blob--2"></span>
            <span class="cs-blob cs-blob--3"></span>
            <span class="cs-blob cs-blob--4"></span>
        </div>

        <div class="cs-bg__layer" style="--d:1">
            @foreach ([
                ['Draft report', 'Due Friday', 'AB', 'bg-ink text-white'],
                ['Done', 'Shared task board', 'NA', 'bg-periwinkle-deep text-ink'],
                ['Review', 'Waiting on Wei Ling', 'WL', 'bg-surface-muted text-ink'],
                ['Sprint demo', 'Thursday, 10am', 'SN', 'bg-ink text-white'],
                ['Testing', 'In progress', 'AB', 'bg-periwinkle-deep text-ink'],
                ['Submit proposal', 'Due Monday', 'NA', 'bg-surface-muted text-ink'],
            ] as $n => [$heading, $note, $who, $tone])
                <div class="cs-float cs-float--{{ $n + 1 }}">
                    <div class="cs-float__in flex items-center gap-2.5 rounded-card bg-surface p-3 shadow-lift">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-pill text-micro font-bold {{ $tone }}">{{ $who }}</span>
                        <span class="flex min-w-0 flex-col">
                            <span class="whitespace-nowrap text-meta font-bold">{{ $heading }}</span>
                            <span class="whitespace-nowrap text-tiny font-semibold text-ink-400">{{ $note }}</span>
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
<div class="relative z-10 flex min-h-dvh items-center justify-center p-7">
    <div class="cs-card flex w-full max-w-[1020px] flex-wrap overflow-hidden rounded-[32px] bg-surface shadow-modal">

        {{-- Form pane --}}
        <div class="flex min-w-[320px] flex-[1_1_420px] flex-col gap-[26px] p-10 sm:px-[42px]">

            <a href="{{ url('/') }}" class="flex items-center gap-[11px]" data-reveal style="--i:0">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-[12px] bg-ink">
                    {{-- Brand mark: three bars, the same motif as the sidebar logo. --}}
                    <svg width="19" height="19" viewBox="0 0 20 20" aria-hidden="true">
                        <rect x="3.2" y="4" width="3.4" height="12" rx="1.3" fill="#c3cff9" />
                        <rect x="8.3" y="4" width="3.4" height="8" rx="1.3" fill="#ffffff" />
                        <rect x="13.4" y="4" width="3.4" height="10" rx="1.3" fill="#8fa4f0" />
                    </svg>
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="text-[16.5px] font-extrabold leading-tight tracking-title">CollabSpace</span>
                    <span class="text-tiny font-semibold text-ink/[.48]">University Group Project Management</span>
                </span>
            </a>

            {{ $slot }}
        </div>

        {{-- Marketing pane --}}
        <div class="hidden min-w-[300px] flex-[1_1_380px] flex-col justify-between gap-[26px] bg-periwinkle p-9 md:flex">

            <div class="flex flex-wrap items-center justify-between gap-3" data-reveal style="--i:8">
                <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">This semester</span>
                <span class="rounded-pill bg-white/70 px-[11px] py-[5px] text-label font-bold">
                    {{ $badge ?? 'Sem 1 · Week 10' }}
                </span>
            </div>

            <div class="flex flex-col gap-3">
                <h2 class="text-pretty text-[27px] font-extrabold leading-[1.18] tracking-title" data-reveal style="--i:9">
                    Group work, without the guesswork.
                </h2>
                <p class="text-pretty text-nav font-medium leading-[1.55] text-ink/[.66]" data-reveal style="--i:10">
                    One board for every assignment — who owns what, what's overdue, and who actually contributed.
                </p>
            </div>

            <ul class="flex flex-col gap-[11px]">
                @foreach ([
                    ['chart', 'bg-surface-muted text-periwinkle-link', 'Shared task board', 'Drag tasks across To Do, In Progress, Done'],
                    ['clock', 'bg-danger-bg text-danger-text', 'Deadline alerts', 'Overdue work is flagged before grading'],
                    ['graduation', 'bg-success-bg text-success-text', 'Contribution reports', 'Fair grading, backed by real activity'],
                ] as $n => [$icon, $tone, $heading, $body])
                    <li class="flex items-center gap-3 rounded-card bg-surface p-[14px] @if ($intro) cs-feature @endif" data-reveal style="--i:{{ 11 + $n }}">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-[12px] {{ $tone }}">
                            <x-icon :name="$icon" size="18" />
                        </span>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="text-nav font-bold tracking-[-0.015em]">{{ $heading }}</span>
                            <span class="text-label font-semibold text-ink-400">{{ $body }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="flex flex-wrap items-center gap-3" data-reveal style="--i:14">
                <div class="flex">
                    <span class="flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-ink text-micro font-bold text-white @if ($intro) cs-avatar @endif">AB</span>
                    <span class="-ml-[9px] flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-surface text-micro font-bold @if ($intro) cs-avatar @endif">NA</span>
                    <span class="-ml-[9px] flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-surface text-micro font-bold @if ($intro) cs-avatar @endif">WL</span>
                    <span class="-ml-[9px] flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-surface text-micro font-bold @if ($intro) cs-avatar @endif">SN</span>
                </div>
                <span class="text-meta font-semibold text-ink/[.62]">Used across the faculty for group coursework</span>
            </div>
        </div>
    </div>
</div>

{{-- See components/layouts/app.blade.php: Livewire supplies Alpine app-wide. --}}
@livewireScripts
</body>
</html>
