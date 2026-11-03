{{--
    "Submit for assessment": the group hands in a report as one frozen PDF of the chosen documents. Expects $group
    (with its subject), $documents (the live ones) and $submissions (this group's, by assessment id, newest first).
    Any member can submit, submit again and withdraw (until the end of the due date). Submitting after the due date is
    allowed and marked Late. The confirm step is inline: the same Submit button, then "Yes, submit".
--}}
@php
    $assessments = $group->subject->assessments;
    $active = $group->subject->activeAssessment();
    $picked = (int) old('assessment_id', $active?->id ?? $assessments->first()?->id);
    $oldOrder = collect(old('documents', []))->map(fn ($id) => (int) $id)->all();
    $items = $documents->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'checked' => in_array($d->id, $oldOrder, true)])->values()->all();
    usort($items, function ($a, $b) use ($oldOrder) {
        $pa = array_search($a['id'], $oldOrder, true);
        $pb = array_search($b['id'], $oldOrder, true);

        return ($pa === false ? PHP_INT_MAX : $pa) <=> ($pb === false ? PHP_INT_MAX : $pb);
    });
    $bag = $errors->first('submission') ?: $errors->first('documents') ?: $errors->first('assessment_id') ?: $errors->first('note');
@endphp

<section id="submit" class="flex scroll-mt-6 flex-col gap-4 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" aria-label="Submit for assessment" data-submit-card
         x-data="{ assessment: {{ $picked ?: 'null' }}, confirming: false, wd: null,
                   items: {{ Js::from($items) }},
                   get chosen() { return this.items.filter(i => i.checked) },
                   move(i, d) { const j = i + d; if (j < 0 || j >= this.items.length) return; const t = this.items[i]; this.items[i] = this.items[j]; this.items[j] = t },
                 }">
    <div class="flex flex-col gap-1">
        <h2 class="text-h3 font-bold tracking-title">Submit for assessment</h2>
        <p class="text-meta text-ink-400">Hand in your report as one PDF made from the documents you choose, in the order you choose.</p>
    </div>

    @if ($bag)
        <div class="rounded-card bg-danger-bg px-4 py-3" role="alert" data-submission-error>
            <p class="text-meta font-semibold text-danger-text">{{ $bag }}</p>
        </div>
    @endif

    @if ($assessments->isEmpty())
        <p class="text-meta text-ink-300" data-submit-empty>This subject has no assessment yet, so there is nothing to submit for.</p>
    @elseif ($documents->isEmpty())
        <p class="text-meta text-ink-300" data-submit-empty>Write a document first. Your group has none yet, so there is nothing to submit.</p>
    @else
        {{-- Where the group stands for each assessment. --}}
        @foreach ($assessments as $assessment)
            @php
                $all = $submissions->get($assessment->id, collect());
                $current = $all->first() && ! $all->first()->isWithdrawn() ? $all->first() : null;
                $history = $all->reject(fn ($s) => $current && $s->is($current))->values();
                $open = \App\Support\Submissions\Submissions::beforeDeadline($assessment);
            @endphp
            <div class="flex flex-col gap-3 rounded-card border border-surface-line bg-surface-soft px-4 py-3.5" x-show="assessment === {{ $assessment->id }}" @if ($picked !== $assessment->id) x-cloak @endif data-submission-panel="{{ $assessment->id }}">
                @if ($current)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5" data-submission-current>
                        <x-table.dot-pill :tone="$current->is_late ? 'warning' : 'success'" data-submission-timing>Submitted · {{ $current->timingLabel() }}</x-table.dot-pill>
                        <span class="min-w-0 flex-1 text-meta font-semibold text-ink-600">
                            {{ $current->submitter?->name ?? 'A former member' }} · {{ $current->submitted_at->format('j M Y, g:i a') }}@if ($current->page_count) · {{ $current->page_count }} {{ Str::plural('page', $current->page_count) }}@endif
                        </span>
                        <x-button variant="secondary" class="!px-4 !py-1.5 !text-meta" :href="route('submissions.pdf', $current)">Download PDF</x-button>
                        @if ($open)
                            <x-table.quiet-action icon="trash" x-on:click="wd = { url: '{{ route('student.submissions.withdraw', $current) }}', method: 'POST', title: 'Withdraw this submission?', body: 'It stops being your current submission. Its PDF is kept as history, and you can submit again until the due date.', action: 'Withdraw', tone: 'danger' }">Withdraw</x-table.quiet-action>
                        @endif
                    </div>
                    @if (filled($current->note))
                        <p class="text-meta text-ink-400">Note: {{ $current->note }}</p>
                    @endif
                @else
                    <p class="text-meta font-semibold text-ink-600" data-submission-none>Not submitted{{ $all->isNotEmpty() ? ' (the last one was withdrawn)' : '' }}.@if (! $open) <span class="text-warning-text">The due date has passed: a submission now is marked Late.</span>@endif</p>
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
                                    <a href="{{ route('submissions.pdf', $past) }}" class="interactive rounded-md font-bold text-periwinkle-link hover:underline">Download</a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @endforeach

        <form method="POST" action="{{ route('student.submissions.store', $group) }}" class="flex flex-col gap-4 border-t border-surface-line pt-4" data-submit-form>
            @csrf

            <div class="flex flex-col gap-2">
                <x-input-label for="submit_assessment" value="Assessment" />
                <select id="submit_assessment" name="assessment_id" x-model.number="assessment"
                        class="h-[52px] w-full max-w-sm rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                    @foreach ($assessments as $assessment)
                        <option value="{{ $assessment->id }}" @selected($picked === $assessment->id)>{{ $assessment->title }}@if ($assessment->due_on) · due {{ $assessment->due_on->format('j M Y') }}@endif</option>
                    @endforeach
                </select>
            </div>

            <fieldset class="flex flex-col gap-2" data-submit-documents>
                <legend class="text-label font-bold uppercase tracking-eyebrow text-ink-400">Documents (tick to include, arrows to reorder)</legend>
                <ul class="flex flex-col gap-1.5">
                    <template x-for="(item, i) in items" :key="item.id">
                        <li class="flex items-center gap-2.5 rounded-card border border-surface-line bg-surface px-3 py-2">
                            <input type="checkbox" x-model="item.checked" class="size-4 rounded border-surface-strong text-ink focus:ring-ink/20" x-bind:aria-label="'Include ' + item.title">
                            <span class="min-w-0 flex-1 truncate text-meta font-semibold" x-text="item.title"></span>
                            <span class="text-label font-bold text-ink-400" x-show="item.checked" x-text="'#' + (chosen.indexOf(item) + 1)"></span>
                            <button type="button" class="interactive btn-motion flex size-7 items-center justify-center rounded-pill text-ink-600 hover:bg-surface-muted disabled:opacity-30" x-bind:disabled="i === 0" x-on:click="move(i, -1)" x-bind:aria-label="'Move ' + item.title + ' up'">
                                <x-icon name="chevron" size="14" class="rotate-180" />
                            </button>
                            <button type="button" class="interactive btn-motion flex size-7 items-center justify-center rounded-pill text-ink-600 hover:bg-surface-muted disabled:opacity-30" x-bind:disabled="i === items.length - 1" x-on:click="move(i, 1)" x-bind:aria-label="'Move ' + item.title + ' down'">
                                <x-icon name="chevron" size="14" />
                            </button>
                        </li>
                    </template>
                </ul>
                <template x-for="item in chosen" :key="'h' + item.id"><input type="hidden" name="documents[]" x-bind:value="item.id"></template>
            </fieldset>

            <div class="flex flex-col gap-2">
                <x-input-label for="submit_note" value="Note (optional)" />
                <x-text-input id="submit_note" name="note" type="text" maxlength="255" :value="old('note')" placeholder="Anything the lecturer should know" />
            </div>

            <div class="flex flex-wrap items-center gap-2.5" x-show="! confirming">
                <x-button type="button" icon="check" x-bind:disabled="chosen.length === 0" x-on:click="confirming = true">Submit</x-button>
                <span class="text-meta text-ink-400" x-show="chosen.length === 0">Tick at least one document.</span>
            </div>

            <div class="flex flex-col gap-3 rounded-card bg-surface-soft px-4 py-3.5" x-show="confirming" x-cloak data-submit-confirm>
                <p class="text-meta font-semibold text-ink-600">
                    This creates a PDF of the selected documents as they are now. You can submit again to replace it, and withdraw until the due date.
                </p>
                <div class="flex flex-wrap items-center gap-2.5">
                    <x-button type="submit">Yes, submit</x-button>
                    <x-button type="button" variant="secondary" x-on:click="confirming = false">Cancel</x-button>
                </div>
            </div>
        </form>
    @endif

    <x-confirm-dialog on="wd" />
</section>
