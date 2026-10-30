@props([
    'done'  => 0,
    'doing' => 0,
    'total' => 0,
    'height' => '9px',
    'on'    => 'white',   // white | periwinkle — decides the track colour
])

@php
    $total = max((int) $total, 0);
    $done  = max((int) $done, 0);
    $doing = max((int) $doing, 0);

    $donePct  = $total > 0 ? min(100, round($done / $total * 100, 2)) : 0;
    $doingPct = $total > 0 ? min(100 - $donePct, round($doing / $total * 100, 2)) : 0;
@endphp

<div {{ $attributes->class([
        'flex w-full overflow-hidden rounded-pill',
        'bg-surface-muted'  => $on === 'white',
        'bg-white/60'       => $on === 'periwinkle',
   ]) }}
   style="height: {{ $height }}"
   role="progressbar"
   aria-valuenow="{{ $donePct }}" aria-valuemin="0" aria-valuemax="100"
   aria-label="{{ $done }} of {{ $total }} complete">
    <span class="block h-full bg-ink" style="width: {{ $donePct }}%"></span>
    <span class="block h-full bg-ink/[.32]" style="width: {{ $doingPct }}%"></span>
</div>
