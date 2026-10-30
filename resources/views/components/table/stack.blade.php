@props([
    'href' => null,     // makes the top line a link
    'title' => null,    // attribute tooltip for the top line
    'icon' => null,     // small tile before the lines (documents)
])

{{--
    Two-line table cell: a semibold line, then a muted line (the slot).
    Used for "name over how it was made", "code over full name", a document
    title over its author, and so on. The one place that markup lives.
--}}
<div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
    @if ($icon)
        <span class="flex size-8 shrink-0 items-center justify-center rounded-[11px] bg-surface-muted text-ink-400">
            <x-icon :name="$icon" size="15" />
        </span>
    @endif

    <div class="flex min-w-0 flex-col gap-0.5">
        @if ($href)
            <a href="{{ $href }}" @if($title) title="{{ $title }}" @endif
               class="interactive w-fit max-w-full truncate rounded-md text-nav font-semibold hover:text-periwinkle-link">{{ $primary }}</a>
        @else
            <span @if($title) title="{{ $title }}" @endif class="text-nav font-semibold">{{ $primary }}</span>
        @endif

        @if (trim((string) $slot) !== '')
            <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-label font-medium text-ink-400">{{ $slot }}</span>
        @endif
    </div>
</div>
