@props(['title' => 'CollabSpace'])

{{--
    Auth shell, from docs/design/CollabSpace Sign In.dc.html.

    Two panes inside one 32px card on a periwinkle field: the form on the left,
    a fixed marketing panel on the right that collapses away under 900px.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-periwinkle text-ink antialiased">

<div class="flex min-h-screen items-center justify-center p-7">
    <div class="flex w-full max-w-[1020px] flex-wrap overflow-hidden rounded-[32px] bg-surface shadow-modal">

        {{-- Form pane --}}
        <div class="flex min-w-[320px] flex-[1_1_420px] flex-col gap-[26px] p-10 sm:px-[42px]">

            <a href="{{ url('/') }}" class="flex items-center gap-[11px]">
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

            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">This semester</span>
                <span class="rounded-pill bg-white/70 px-[11px] py-[5px] text-label font-bold">
                    {{ $badge ?? 'Sem 1 · Week 10' }}
                </span>
            </div>

            <div class="flex flex-col gap-3">
                <h2 class="text-pretty text-[27px] font-extrabold leading-[1.18] tracking-title">
                    Group work, without the guesswork.
                </h2>
                <p class="text-pretty text-nav font-medium leading-[1.55] text-ink/[.66]">
                    One board for every assignment — who owns what, what's overdue, and who actually contributed.
                </p>
            </div>

            <ul class="flex flex-col gap-[11px]">
                @foreach ([
                    ['chart', 'bg-surface-muted text-periwinkle-link', 'Shared task board', 'Drag tasks across To Do, In Progress, Done'],
                    ['clock', 'bg-danger-bg text-danger-text', 'Deadline alerts', 'Overdue work is flagged before grading'],
                    ['graduation', 'bg-success-bg text-success-text', 'Contribution reports', 'Fair grading, backed by real activity'],
                ] as [$icon, $tone, $heading, $body])
                    <li class="flex items-center gap-3 rounded-card bg-surface p-[14px]">
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

            <div class="flex flex-wrap items-center gap-3">
                <div class="flex">
                    <span class="flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-ink text-micro font-bold text-white">AB</span>
                    <span class="-ml-[9px] flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-surface text-micro font-bold">NA</span>
                    <span class="-ml-[9px] flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-surface text-micro font-bold">WL</span>
                    <span class="-ml-[9px] flex size-[30px] items-center justify-center rounded-pill border-2 border-periwinkle bg-surface text-micro font-bold">SN</span>
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
