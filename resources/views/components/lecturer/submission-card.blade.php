@props([
    'group',        // the Group
    'assessment',   // the Assessment the page is about
])

@php
    $all = \App\Models\Submission::query()->where('group_id', $group->id)->where('assessment_id', $assessment->id)
        ->with('submitter:id,name', 'withdrawer:id,name')->orderByDesc('submitted_at')->orderByDesc('id')->get();
    $latest = $all->first();
    $current = $latest && ! $latest->isWithdrawn() ? $latest : null;
    $history = $all->reject(fn ($s) => $current && $s->is($current))->values();
@endphp

{{-- What the group handed in for this assessment: one frozen PDF of its documents. Read only; evidence, not a grade. --}}
<section class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" aria-label="Submission" data-submission-card>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h2 class="text-nav font-bold">Submission</h2>
        <x-submission-chip :submission="$latest" long />
        <span class="min-w-0 flex-1 text-meta font-semibold text-ink-400">{{ $assessment->title }}</span>

        @if ($current)
            <x-button variant="secondary" class="!px-4 !py-1.5 !text-meta" :href="route('submissions.pdf', ['submission' => $current, 'inline' => 1])" target="_blank" rel="noopener">Open PDF</x-button>
            <x-button variant="secondary" class="!px-4 !py-1.5 !text-meta" :href="route('submissions.pdf', $current)">Download</x-button>
        @endif
    </div>

    @if ($current)
        <p class="text-meta text-ink-600" data-submission-facts>
            Submitted by {{ $current->submitter?->name ?? 'a former member' }} · {{ $current->submitted_at->format('j M Y, g:i a') }}@if ($current->page_count) · {{ $current->page_count }} {{ Str::plural('page', $current->page_count) }}@endif
        </p>
        @if (filled($current->note))
            <p class="text-meta text-ink-400">Note: {{ $current->note }}</p>
        @endif
    @elseif ($latest)
        <p class="text-meta text-ink-400">Withdrawn by {{ $latest->withdrawer?->name ?? 'a member' }} on {{ $latest->withdrawn_at->format('j M Y, g:i a') }}. Its PDF is kept in the history.</p>
    @else
        <p class="text-meta text-ink-300">The group has not submitted for this assessment.</p>
    @endif

    @if ($history->isNotEmpty())
        <details class="text-meta" data-submission-history>
            <summary class="interactive cursor-pointer rounded-md font-bold text-periwinkle-link">History ({{ $history->count() }})</summary>
            <ul class="mt-2 flex flex-col gap-1.5">
                @foreach ($history as $past)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 text-ink-600">
                        <span class="font-semibold">{{ $past->submitted_at->format('j M Y, g:i a') }}</span>
                        <span class="text-ink-400">{{ $past->submitter?->name ?? 'A former member' }}</span>
                        <span>{{ $past->isWithdrawn() ? 'Withdrawn' : 'Replaced' }} · {{ $past->timingLabel() }}</span>
                        <a href="{{ route('submissions.pdf', ['submission' => $past, 'inline' => 1]) }}" target="_blank" rel="noopener" class="interactive rounded-md font-bold text-periwinkle-link hover:underline">Open</a>
                        <a href="{{ route('submissions.pdf', $past) }}" class="interactive rounded-md font-bold text-periwinkle-link hover:underline">Download</a>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</section>
