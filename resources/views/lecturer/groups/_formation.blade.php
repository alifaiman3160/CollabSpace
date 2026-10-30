@php
    $meta = $group->formation_meta ?? [];
    $peers = $group->formationPeers();
    $when = $group->formed_at ?? $group->created_at;
    $who = $group->formedBy?->name;
@endphp

{{-- How this group came to exist. A group that is struggling three weeks in
     means something different if the students chose each other than if it was
     dealt out to balance ability, so the record is shown where the lecturer is
     already looking at the group. --}}
<section class="flex flex-col gap-2.5 rounded-panel border border-surface-line bg-surface-soft p-5" aria-label="How this group was formed">
    <div class="flex flex-wrap items-center gap-2.5">
        <x-badge :tone="$group->formation_method === 'manual' ? 'neutral' : 'info'">{{ $group->formationLabel() }}</x-badge>
        <span class="text-meta text-ink-400" title="{{ $when->format('j F Y, g:ia') }}">
            {{ $when->format('j F Y') }}{{ $who ? ' by '.$who : '' }}
        </span>
    </div>

    @if ($group->formation_method === 'balanced')
        <p class="text-meta leading-[1.6] text-ink-600">
            Dealt out by a snake draft on CGPA, together with
            @if ($peers->isEmpty())
                no other group.
            @else
                {{ $peers->count() }} other {{ Str::plural('group', $peers->count()) }}:
                @foreach ($peers as $peer)
                    <a href="{{ route('lecturer.groups.show', $peer) }}"
                       class="font-semibold text-periwinkle-link hover:underline">{{ $peer->name }}</a>{{ $loop->last ? '.' : ',' }}
                @endforeach
            @endif
        </p>

        <p class="text-meta leading-[1.6] text-ink-400">
            @if (($meta['average_cgpa'] ?? null) !== null)
                Average CGPA when it was formed: <span class="font-bold text-ink-600">{{ number_format($meta['average_cgpa'], 2) }}</span>
                @if (($meta['spread_across_batch'] ?? null) !== null)
                    (the batch's averages were {{ number_format($meta['spread_across_batch'], 2) }} apart)
                @endif.
            @else
                No CGPA was recorded for anyone in it at the time.
            @endif
            @if (($meta['cgpa_unknown'] ?? 0) > 0)
                {{ $meta['cgpa_unknown'] }} {{ Str::plural('member', $meta['cgpa_unknown']) }} had none, and {{ $meta['cgpa_unknown'] === 1 ? 'was' : 'were' }} left out of that average.
            @endif
            CGPAs are entered by hand and may have changed since.
        </p>
    @elseif ($group->formation_method === 'imported')
        <p class="text-meta leading-[1.6] text-ink-600">
            Imported from the students' own choices in
            <span class="font-semibold">{{ $meta['source'] ?? 'a CSV file' }}</span>@if (! empty($meta['rows'])),
                {{ Str::plural('row', count($meta['rows'])) }} {{ implode(', ', $meta['rows']) }}@endif.
            @if (($meta['matched_by_hand'] ?? 0) > 0)
                {{ $meta['matched_by_hand'] }} {{ Str::plural('student', $meta['matched_by_hand']) }} had to be matched by hand.
            @endif
            @if (! empty($meta['limit_raised']))
                The size limit was raised for this group.
            @endif
            @if (! empty($meta['problem_groups_skipped']))
                Groups with problems were left out of the same import.
            @endif
        </p>
    @else
        <p class="text-meta leading-[1.6] text-ink-400">
            Created by hand, with members assigned one at a time.
        </p>
    @endif
</section>
