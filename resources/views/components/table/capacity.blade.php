@props([
    'names' => [],
    'count' => 0,
    'size'  => 0,
])

@php
    $count = (int) $count;
    $size  = (int) $size;
    $left  = $size - $count;
@endphp

{{-- Who is in the group and how close it is to its limit. At most one status shows. --}}
<div {{ $attributes->class('flex flex-col gap-1.5') }}>
    <div class="flex items-center gap-2.5">
        @if ($count > 0)
            <x-table.avatar-stack :names="$names" />
        @endif
        <span class="text-meta font-semibold tabular-nums">{{ $count }}/{{ $size }}</span>
    </div>

    @if ($size > 0 && $count >= $size)
        <x-table.dot-pill tone="success" class="w-fit">Full</x-table.dot-pill>
    @elseif ($count * 2 < $size)
        <x-table.dot-pill tone="warning" class="w-fit">Needs members</x-table.dot-pill>
    @elseif ($left === 1)
        <span class="text-label font-medium text-ink-400">1 slot left</span>
    @endif
</div>
