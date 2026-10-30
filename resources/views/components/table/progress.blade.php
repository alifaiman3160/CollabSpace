@props([
    'done'    => 0,
    'total'   => 0,
    'overdue' => 0,
])

@php
    $done = (int) $done;
    $total = (int) $total;
    $overdue = (int) $overdue;
    $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
@endphp

{{-- Thin bar, percentage to its right, count underneath, overdue only when there is some. --}}
<div {{ $attributes->class('flex min-w-[170px] flex-col gap-1') }}>
    <div class="flex items-center gap-2.5">
        <x-progress-bar :done="$done" :total="$total" height="6px" class="flex-1" />
        <span class="w-9 shrink-0 text-right text-meta font-bold tabular-nums {{ $overdue > 0 ? 'text-danger-text' : 'text-ink' }}">{{ $pct }}%</span>
    </div>

    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="text-label font-medium text-ink-400">
            @if ($total > 0)
                {{ $done }} of {{ $total }} {{ Str::plural('task', $total) }}
            @else
                No tasks yet
            @endif
        </span>

        @if ($overdue > 0)
            <x-table.dot-pill tone="danger">{{ $overdue }} overdue</x-table.dot-pill>
        @endif
    </div>
</div>
