@props([
    'done' => 0,
    'total' => 0,
    'overdue' => 0,
    'label' => 'Task progress',
])

@php
    $done = (int) $done;
    $total = (int) $total;
    $overdue = (int) $overdue;
    $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
@endphp

{{-- Progress block from the lecturer dashboard design: eyebrow + percentage,
     bar, then the count and an overdue flag when there is one. --}}
<div {{ $attributes->class('flex min-w-[180px] flex-col gap-1.5') }}>
    <div class="flex items-baseline justify-between gap-2">
        <span class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">{{ $label }}</span>
        <span class="text-body font-extrabold tracking-[-0.02em] {{ $overdue > 0 ? 'text-danger-text' : 'text-ink' }}">
            {{ $pct }}%
        </span>
    </div>

    <x-progress-bar :done="$done" :total="$total" />

    <div class="flex flex-wrap items-center gap-2">
        <span class="text-label font-semibold text-ink-400">
            @if ($total > 0)
                {{ $done }} of {{ $total }} {{ Str::plural('task', $total) }}
            @else
                No tasks yet
            @endif
        </span>

        @if ($overdue > 0)
            <x-badge tone="danger">{{ $overdue }} overdue</x-badge>
        @endif
    </div>
</div>
