@props([
    'icon' => null,
    'tone' => 'danger',   // danger | neutral
    'href' => null,
])

@php
    $classes = [
        // Always visible. Quiet, not hidden.
        'interactive inline-flex items-center gap-1.5 rounded-pill px-3 py-2 text-meta font-semibold disabled:opacity-50',
        'text-ink-400' => $tone === 'danger',
        'text-ink-600' => $tone !== 'danger',
        'hover:bg-danger-bg hover:text-danger-text focus-visible:bg-danger-bg focus-visible:text-danger-text' => $tone === 'danger',
        'hover:bg-surface-muted hover:text-ink focus-visible:bg-surface-muted focus-visible:text-ink' => $tone !== 'danger',
    ];
@endphp

{{--
    A muted text-and-icon control for a row's secondary action.

    tone="danger"  destructive (Delete, Remove, Deactivate): grey, red on hover and focus.
    tone="neutral" ordinary workflow (Groups, Report, Reset password): a step darker.

    Neither is ever revealed by hover; both are on screen at all times.
--}}
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" size="15" />@endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(["type" => "button"])->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" size="15" />@endif
        {{ $slot }}
    </button>
@endif
