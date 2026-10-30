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
     bar, then the count and an overdue flag when there is one.

     Pass an empty label to drop the eyebrow. Inside a table whose column is
     already headed "Task progress", repeating it on every row is noise. --}}
<div {{ $attributes->class('flex min-w-[180px] flex-col gap-1.5') }}>
    {{-- items-center, not items-baseline.
         Aligning baselines is right for text sharing a line, but these two do
         not: the label is 10.5px on a 1.3 line box and the percentage is 13px
         on a 1.5 one, so a shared baseline pushed the percentage about three
         and a half pixels above the label and read as a mistake. Centring the
         two boxes puts them level, and leading-none stops the percentage's
         generous line height adding height the row does not need. --}}
    <div class="flex items-center justify-between gap-2">
        @if (filled($label))
            <span class="text-tiny font-bold uppercase leading-none tracking-eyebrow text-ink-300">{{ $label }}</span>
        @endif

        <span class="text-body font-extrabold leading-none tracking-[-0.02em] {{ $overdue > 0 ? 'text-danger-text' : 'text-ink' }}">{{ $pct }}%</span>
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
