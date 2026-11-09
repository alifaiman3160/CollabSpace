@props([
    'names' => [],   // member names, in the order to show
    'max'   => 4,
    'leader' => null, // the leader's name: a small star on that avatar, with a tooltip
])

@php
    $names = collect($names)->values();
    $shown = $names->take($max);
    $more  = $names->count() - $shown->count();
@endphp

{{-- Overlapping initials; "+N" for the rest. Names are on each circle's title. --}}
<span {{ $attributes->class('flex items-center -space-x-2') }}>
    @foreach ($shown as $name)
        @if ($leader !== null && $name === $leader)
            <span class="relative inline-flex" data-leader-mark data-tooltip="Leader: {{ $name }}">
                <x-avatar :name="$name" class="!size-7 !text-tiny ring-2 ring-surface" />
                <span class="absolute -right-1 -top-1 flex size-3.5 items-center justify-center rounded-pill bg-warning-bg text-warning-text ring-1 ring-surface" aria-hidden="true"><x-icon name="star" size="9" /></span>
            </span>
        @else
            <x-avatar :name="$name" class="!size-7 !text-tiny ring-2 ring-surface" />
        @endif
    @endforeach

    @if ($more > 0)
        <span class="inline-flex size-7 items-center justify-center rounded-pill bg-surface-muted text-tiny font-bold text-ink-600 ring-2 ring-surface"
              title="{{ $more }} more">+{{ $more }}</span>
    @endif
</span>
