@props([
    'names' => [],   // member names, in the order to show
    'max'   => 4,
])

@php
    $names = collect($names)->values();
    $shown = $names->take($max);
    $more  = $names->count() - $shown->count();
@endphp

{{-- Overlapping initials; "+N" for the rest. Names are on each circle's title. --}}
<span {{ $attributes->class('flex items-center -space-x-2') }}>
    @foreach ($shown as $name)
        <x-avatar :name="$name" class="!size-7 !text-tiny ring-2 ring-surface" />
    @endforeach

    @if ($more > 0)
        <span class="inline-flex size-7 items-center justify-center rounded-pill bg-surface-muted text-tiny font-bold text-ink-600 ring-2 ring-surface"
              title="{{ $more }} more">+{{ $more }}</span>
    @endif
</span>
