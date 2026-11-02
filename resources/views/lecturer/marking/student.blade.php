<x-layouts.app :title="$student->name.' — Marking — CollabSpace'"
               :heading="$student->name"
               :subtitle="$group->name.' — '.($group->section ? 'Section '.$group->section->name.' — ' : '').$rubric->name.', version '.$rubric->version"
               :back="route('lecturer.marking.group', [$rubric, $group])"
               :back-label="$group->name">

    <div class="flex flex-col gap-[18px]" x-data="{ dirty: false, confirm: null }"
         x-on:input="dirty = true" x-on:change="dirty = true">

        <x-flash />

        <div class="flex flex-wrap items-center gap-2" data-marking-context>
            <x-assessment-chip :assessment="$rubric->assessment" />
            <x-section-chip :section="$group->section" :code="$rubric->subject->code ?? null" />
        </div>

        {{-- One look: where the totals stand, and the two things to do. --}}
        @php
            $pendingAccept = $approval ? 0 : count(app(\App\Support\Marking\SuggestionAcceptor::class)->pending($rubric, $group, $student));
            $tiles = $approval
                ? collect($approval->snapshot['sections'])->map(fn ($x) => ['title' => $x['title'], 'total' => $x['total'], 'maximum' => $x['maximum'], 'note' => null])
                : collect($sheet['sections'])->map(fn ($x) => [
                    'title' => $x['section']->title,
                    'total' => $x['complete'] ? $x['total'] : null,
                    'maximum' => $x['maximum'],
                    'note' => $x['complete'] ? null : ($x['decided'].' of '.$x['criteria_count'].' chosen'),
                ]);
            $overall = $approval ? (float) $approval->total : $sheet['total'];
            $hadSuggestions = collect($sheet['sections'])->flatMap(fn ($x) => $x['rows'])->contains(fn ($r) => $r['criterion']->isSuggested() && $r['baseline_level'] !== null);
        @endphp

        <section class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" data-top>
            {{-- Equal tiles on one row (stacked on a phone). Every tile has the same three lines: label, figure, caption. --}}
            <div class="grid gap-3 sm:grid-flow-col sm:auto-cols-fr" data-totals>
                <div class="flex flex-col justify-between gap-1.5 rounded-card border border-periwinkle bg-periwinkle-soft px-4 py-3 text-ink shadow-subtle" data-overall data-tile-box>
                    <span class="text-label font-bold uppercase tracking-eyebrow text-ink-600">Total</span>
                    <span class="flex h-[30px] items-baseline gap-1.5 tabular-nums">
                        @if ($overall !== null)
                            <span class="text-[28px] font-extrabold leading-none tracking-stat">{{ \App\Support\Rubric\RubricScoring::trim($overall) }}</span>
                            @if ($sheet['maximum'] !== null)<span class="text-meta font-semibold text-ink-600">of {{ \App\Support\Rubric\RubricScoring::trim($sheet['maximum']) }}</span>@endif
                        @else
                            <span class="text-nav font-semibold text-ink-600" data-not-yet>Not yet</span>
                        @endif
                    </span>
                    <span class="flex min-h-[22px] items-center gap-2 text-label font-medium text-ink-600">
                        @if ($overall === null)
                            <span class="rounded-pill bg-warning-bg px-2 py-0.5 text-micro font-bold uppercase tracking-eyebrow text-warning-text" data-incomplete-badge>Incomplete</span>
                            {{ $sheet['decided'] }} of {{ $sheet['criteria'] }} decided
                        @endif
                    </span>
                </div>

                @foreach ($tiles as $tile)
                    <div class="flex flex-col justify-between gap-1.5 rounded-card border border-surface-line bg-surface px-4 py-3 shadow-subtle" data-tile data-tile-box>
                        <span class="truncate text-label font-bold uppercase tracking-eyebrow text-ink-400" title="{{ $tile['title'] }}">{{ \Illuminate\Support\Str::before($tile['title'], ' – ') }}</span>
                        <span class="flex h-[30px] items-baseline gap-1.5 tabular-nums">
                            @if ($tile['total'] !== null)
                                <span class="text-[28px] font-extrabold leading-none tracking-stat">{{ \App\Support\Rubric\RubricScoring::trim($tile['total']) }}</span>
                                @if ($tile['maximum'] !== null)<span class="text-meta font-semibold text-ink-400">of {{ \App\Support\Rubric\RubricScoring::trim($tile['maximum']) }}</span>@endif
                            @else
                                <span class="text-nav font-semibold text-ink-500" data-not-yet>Not yet</span>
                            @endif
                        </span>
                        <span class="flex min-h-[22px] items-center gap-2 text-label font-medium text-ink-400">
                            @if ($tile['total'] === null && ! $approval)
                                <span class="rounded-pill bg-warning-bg px-2 py-0.5 text-micro font-bold uppercase tracking-eyebrow text-warning-text" data-incomplete-badge>Incomplete</span>
                                {{ $tile['note'] }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                @unless ($approval)
                    <form id="accept-form" class="hidden" method="POST" action="{{ route('lecturer.marking.accept', [$rubric, $group, $student]) }}">@csrf</form>

                    <x-button type="submit" form="accept-form" icon="check" :disabled="$pendingAccept === 0"
                              title="Saves the suggested level for each System-suggested criterion you have not decided yet. It never changes a level you chose, and it does not approve anything."
                              x-bind:disabled="dirty || {{ $pendingAccept === 0 ? 'true' : 'false' }}" data-accept>
                        Accept suggestions @if ($pendingAccept > 0)({{ $pendingAccept }})@endif
                    </x-button>
                    @if ($pendingAccept === 0)
                        <span class="text-meta font-medium text-ink-400" data-accept-hint>{{ $hadSuggestions ? 'All suggestions accepted' : 'Nothing to accept' }}</span>
                    @endif

                    <form method="POST" action="{{ route('lecturer.marking.approve', [$rubric, $group, $student]) }}">
                        @csrf
                        <x-button variant="secondary" type="submit" :disabled="! $sheet['complete']" x-bind:disabled="dirty || {{ $sheet['complete'] ? 'false' : 'true' }}" data-approve-top>Approve marks</x-button>
                    </form>
                @endunless

                <span class="ml-auto flex flex-wrap items-center gap-2" data-neighbours>
                    <span class="text-meta font-semibold tabular-nums text-ink-400">Student {{ $position[0] }} of {{ $position[1] }}</span>
                    @if ($previous)
                        <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.marking.student', [$rubric, $group, $previous])" data-previous>Previous student</x-button>
                    @endif
                    @if ($next)
                        <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.marking.student', [$rubric, $group, $next])" data-next>Next student</x-button>
                    @endif
                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta" :href="route('lecturer.marking.group', [$rubric, $group])" data-all-students>All students</x-button>
                </span>
            </div>

        </section>

        {{-- Where this student's marks stand. --}}
        @if ($approval)
            <section class="flex flex-wrap items-center gap-3 rounded-panel border border-success-line bg-success-bg px-5 py-4" data-status="approved">
                <x-icon name="check" size="18" class="shrink-0 text-success-text" />
                <p class="min-w-0 flex-1 text-body font-bold text-success-text">
                    Approved: {{ \App\Support\Rubric\RubricScoring::trim((float) $approval->total) }}
                    <span class="font-medium">
                        by {{ $approval->approver?->name ?? 'a lecturer' }} on {{ $approval->approved_at->format('j M Y, g:ia') }}.
                        These marks are frozen as they were approved.
                    </span>
                </p>

                <x-table.quiet-action icon="lock" aria-label="Revoke the approval of {{ $student->name }}"
                    x-on:click="confirm = {
                        url: '{{ route('lecturer.marking.revoke', [$rubric, $group, $student]) }}',
                        method: 'DELETE',
                        title: 'Revoke this approval?',
                        body: {{ Js::from('The approved marks stop being final and the choices can be changed again. The approval is kept on record with who revoked it and when.') }},
                        action: 'Revoke approval',
                        tone: 'danger',
                    }">Revoke approval</x-table.quiet-action>
            </section>
        @elseif ($sheet['complete'])
            <section class="flex items-center gap-3 rounded-panel border border-periwinkle-deep bg-periwinkle-soft px-5 py-4" data-status="complete">
                <x-icon name="check" size="18" class="shrink-0 text-periwinkle-link" />
                <p class="min-w-0 flex-1 text-body font-bold text-ink">
                    Every criterion has a level. Total: {{ \App\Support\Rubric\RubricScoring::trim($sheet['total']) }}.
                    <span class="font-medium text-ink-600">Nothing is final until you approve it.</span>
                </p>
            </section>
        @else
            <section class="flex flex-col gap-2 rounded-panel border border-warning-bg bg-warning-bg px-5 py-4" data-status="incomplete">
                <p class="flex items-start gap-2 text-body font-bold text-warning-text">
                    <x-icon name="alert" size="18" class="mt-0.5 shrink-0" />
                    <span>
                        Incomplete: {{ $sheet['decided'] }} of {{ $sheet['criteria'] }} criteria decided.
                        <span class="block text-meta font-medium">Still needed:</span>
                    </span>
                </p>
                <ul class="list-disc pl-12 text-meta leading-[1.5] text-warning-text">
                    @foreach ($sheet['blockers'] as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
                <p class="pl-7 text-label text-warning-text">There is no total, and it cannot be approved, until every criterion below has a level you chose.</p>
            </section>
        @endif

        @if ($approval)
            {{-- Read only: the sheet as it was approved. --}}
            @foreach ($approval->snapshot['sections'] as $section)
                <section class="flex flex-col gap-3" data-approved-section>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <h2 class="text-h3 font-extrabold tracking-title">{{ $section['title'] }}</h2>
                        <span class="text-nav font-bold tabular-nums" data-section-total>
                            {{ \App\Support\Rubric\RubricScoring::trim($section['total']) }}@if ($section['maximum'] !== null) <span class="font-medium text-ink-400">of {{ \App\Support\Rubric\RubricScoring::trim($section['maximum']) }}</span>@endif
                            @if ($section['scaling'])
                                <span class="text-meta font-medium text-ink-400" data-scaling>
                                    · scaled up from {{ \App\Support\Rubric\RubricScoring::trim($section['scaling']['suggested_marks']) }} out of {{ \App\Support\Rubric\RubricScoring::trim($section['scaling']['suggested_maximum']) }} marks. Manual criteria are not counted.
                                </span>
                            @endif
                        </span>
                    </div>
                    <p class="-mt-1 text-meta text-ink-400">{{ ucfirst($section['formula_text']) }}.</p>

                    @if ($section['method'] === 'add' && ! $section['scaling'])
                        <p class="text-meta text-ink-600">Every criterion counted, the manual entries added to the System-suggested ones.</p>
                    @endif

                    <x-data-table :headings="['Criterion', 'Chosen level', 'Suggestions', ['Marks', 'right']]" min-width="760px">
                        @foreach ($section['criteria'] as $c)
                            <tr>
                                <td class="align-top">
                                    <x-table.stack>
                                        <x-slot:primary>{{ $c['name'] }}</x-slot:primary>
                                        {{ $c['mode'] === 'suggested' ? 'System-suggested' : 'Manual' }}@unless ($c['counts'] ?? true) · not counted in the total @endunless
                                    </x-table.stack>
                                </td>
                                <td class="align-top">
                                    <x-table.stack>
                                        <x-slot:primary>{{ \App\Models\RubricLevel::describe($c['level']['label'], $c['level']['marks'], $c['level']['range']) }}</x-slot:primary>
                                        @if ($c['source'] === 'ai') Accepted the AI's view
                                        @elseif ($c['source'] === 'rule_based') Accepted the level from the numbers
                                        @else Own choice @endif
                                    </x-table.stack>
                                    @if ($c['override_reason'])
                                        <p class="mt-1 text-label leading-[1.4] text-ink-600">Reason: {{ $c['override_reason'] }}</p>
                                    @endif
                                </td>
                                <td class="align-top text-label leading-[1.45] text-ink-400">
                                    @if ($c['insufficient_data'])
                                        Not enough activity to compare: {{ $c['needs_input_reason'] }}
                                    @elseif ($c['baseline'] || $c['ai'])
                                        @if ($c['baseline'])<span class="block">From the numbers: {{ \App\Models\RubricLevel::describe($c['baseline']['label'], $c['baseline']['marks'], $c['baseline']['range']) }}</span>@endif
                                        @if ($c['ai'])<span class="block">AI's view: {{ \App\Models\RubricLevel::describe($c['ai']['label'], $c['ai']['marks'], $c['ai']['range']) }}</span>@endif
                                    @else
                                        —
                                    @endif
                                </td>
                                @if ($c['counts'] ?? true)
                                    <td class="num align-top text-nav font-bold">{{ \App\Support\Rubric\RubricScoring::trim($c['marks']) }}</td>
                                @else
                                    <td class="num align-top text-label font-semibold text-ink-400" data-not-counted-marks>Not counted (scaled)</td>
                                @endif
                            </tr>
                        @endforeach
                    </x-data-table>
                </section>
            @endforeach
        @else
            <form id="marking-form" method="POST" action="{{ route('lecturer.marking.save', [$rubric, $group, $student]) }}" class="flex flex-col gap-[18px]">
                @csrf

                @foreach ($sheet['sections'] as $s)
                    @php
                        $section = $s['section'];
                        $firstId = $section->id;
                    @endphp

                    <section class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" data-section-id="{{ $section->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h2 class="text-h3 font-extrabold tracking-title">{{ $section->title }}</h2>
                                <x-badge :tone="$section->isSuggested() ? 'info' : 'neutral'">{{ $s['mixed'] ? 'Mixed' : ($section->isSuggested() ? 'System-suggested' : 'Manual') }}</x-badge>
                            </div>

                            {{-- This section's total, or why it has none yet. --}}
                            <p class="text-right" data-section-total>
                                @if ($s['complete'])
                                    <span class="text-nav font-bold tabular-nums">{{ \App\Support\Rubric\RubricScoring::trim($s['total']) }}</span>
                                    @if ($s['maximum'] !== null)<span class="text-meta font-medium text-ink-400">of {{ \App\Support\Rubric\RubricScoring::trim($s['maximum']) }}</span>@endif
                                @else
                                    <span class="text-nav font-bold text-warning-text">Incomplete</span>
                                    <span class="block text-label font-medium tabular-nums text-ink-400">
                                        @if ($s['scaled'])
                                            {{ $s['decided'] }} of {{ $s['criteria_count'] }} System-suggested criteria chosen
                                        @else
                                            {{ \App\Support\Rubric\RubricScoring::trim($s['so_far']) }}@if ($s['maximum'] !== null) of {{ \App\Support\Rubric\RubricScoring::trim($s['maximum']) }}@endif so far,
                                            {{ $s['undecided'] }} of {{ $s['criteria_count'] }} undecided
                                        @endif
                                    </span>
                                @endif
                            </p>
                        </div>

                        <p class="text-meta text-ink-400">{{ ucfirst($section->scoringExplained()) }}.</p>

                        @if ($s['mixed'])
                            @php $chosen = old('methods.'.$section->id, $s['method']) ?? ''; @endphp
                            <div class="flex flex-col gap-2 rounded-card border border-surface-line bg-surface-soft p-4"
                                 x-data="{ m: @js((string) $chosen) }" data-method>
                                <label for="method-{{ $section->id }}" class="text-meta font-bold text-ink-700">How is this section totalled?</label>
                                <select id="method-{{ $section->id }}" name="methods[{{ $section->id }}]" x-model="m"
                                        class="w-full rounded-[14px] border border-surface-line bg-surface px-3 py-2.5 text-meta text-ink focus:border-ink focus:ring-0">
                                    <option value="">Choose…</option>
                                    <option value="add" @selected($chosen === 'add')>Add up every criterion</option>
                                    <option value="scale" @selected($chosen === 'scale')>Scale the System-suggested criteria</option>
                                </select>
                                <x-input-error :messages="$errors->get('methods.'.$section->id)" />

                                {{-- One short line, for the choice made. --}}
                                <p class="text-label leading-[1.5] text-ink-400" data-method-help>
                                    <x-fade tag="span" :leave="false" x-show="m === ''">Add: every criterion counts. Scale: only System-suggested criteria count.</x-fade>
                                    <x-fade tag="span" :leave="false" x-show="m === 'add'" x-cloak>Every criterion counts. All the marks are added up.</x-fade>
                                    <x-fade tag="span" :leave="false" x-show="m === 'scale'" x-cloak>Only System-suggested criteria count. Manual ones are left out.</x-fade>
                                </p>

                                @if ($s['method_defaulted'])
                                    <p class="text-label leading-[1.5] text-ink-400" data-method-default>
                                        We picked Add because this section uses level marks only. You can change it.
                                    </p>
                                @endif

                                @if ($s['scaling'])
                                    <p class="text-meta font-semibold text-ink-600" data-scaling>
                                        System-suggested marks: {{ \App\Support\Rubric\RubricScoring::trim($s['scaling']['suggested_marks']) }} out of {{ \App\Support\Rubric\RubricScoring::trim($s['scaling']['suggested_maximum']) }}.
                                        Scaled to {{ \App\Support\Rubric\RubricScoring::trim($s['scaling']['section_maximum']) }}: {{ \App\Support\Rubric\RubricScoring::trim(round($s['scaling']['suggested_marks'] * $s['scaling']['factor'], 2)) }}.
                                    </p>
                                @endif
                            </div>
                        @endif

                        <div class="flex flex-col divide-y divide-surface-strong" data-rows>
                        @foreach ($s['rows'] as $row)
                            @php
                                $criterion = $row['criterion'];
                                $sug = $row['suggestion'];
                                $selected = old('levels.'.$criterion->id, $row['selected_id']);
                                $descriptions = $row['levels']->mapWithKeys(fn ($l) => [$l->id => $l->descriptor ?? ''])->all();
                                $offered = array_values(array_filter([$sug?->baseline_level_id, $sug?->ai_level_id]));
                                $baseId = $row['baseline_level']?->id;
                                $ai = $row['ai_level'];
                                $aiDiffers = $ai !== null && $ai->id !== $baseId;

                                $baselineNote = preg_replace('/\s*This places it at level \d+ of \d+\.\s*$/', '', (string) $sug?->baseline_note);

                                // The one sentence on the row. The fuller wording is under "Why?".
                                $sentence = match (true) {
                                    ! $row['counts'] => null,
                                    $row['baseline_level'] !== null && $sug !== null && is_array($sug->facts)
                                        => \App\Support\Marking\EvidenceFacts::sentence($criterion->evidence_type, $sug->facts) ?? $baselineNote,
                                    $row['baseline_level'] !== null => $baselineNote,
                                    default => null,
                                };
                                $youChoose = $row['counts'] && ($row['baseline_level'] === null);
                                $youChooseText = ($row['insufficient'] ? \App\Support\Marking\Baseline::INSUFFICIENT.' ' : '').'You choose this one.';
                                // Older suggestions carry "This places it at level 4 of 5." on the end; the level has its own small line now.
                                $levelPosition = $baseId !== null ? 'Level '.($row['levels']->search(fn ($l) => $l->id === $baseId) + 1).' of '.$row['levels']->count() : null;

                                // "Why?" only where there is something to say; otherwise a guide to the levels, if the rubric describes them.
                                $hasWhy = $criterion->isSuggested() && $sug && (is_array($sug->facts) || filled($sug->baseline_note) || filled($sug->ai_rationale) || filled($sug->needs_input_reason));
                                $guide = $row['levels']->filter(fn ($l) => filled(trim((string) $l->descriptor)));
                            @endphp

                            <article class="@container py-2.5"
                                     data-criterion-id="{{ $criterion->id }}" @if (! $row['counts']) data-not-counted @endif
                                     x-data="{ sel: @js((string) ($selected ?? '')), offered: @js($offered), has: @js($offered !== []), descriptions: @js($descriptions), why: false }">

                                {{-- Two columns once the row is wide enough (the box is measured, not the screen, because the sidebar takes room); stacked below that. --}}
                                <div class="grid gap-3 @[700px]:grid-cols-[38%_minmax(0,1fr)] @[700px]:items-center @[700px]:gap-x-6" data-row>

                                    <div class="flex min-w-0 flex-col gap-0.5" data-info>
                                        <h3 class="text-nav font-bold">{{ $criterion->name }}</h3>
                                        <span class="text-label text-ink-400">
                                            {{ $criterion->isSuggested() ? 'System-suggested' : 'Manual' }}
                                            @if ($criterion->weight !== null) · weight {{ rtrim(rtrim($criterion->weight, '0'), '.') }}@endif
                                            @unless ($row['counts']) · <span class="font-bold text-ink-600">not counted</span>@endunless
                                        </span>

                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                            @if (! $row['counts'])
                                                <p class="text-meta leading-[1.45] text-ink-600" data-not-counted-note>
                                                    Not counted in this section's total, so it needs no level. Choose one only if you want it on record.
                                                </p>
                                            @elseif ($youChoose)
                                                <p class="text-meta leading-[1.45] text-ink-600" data-you-choose>{{ $youChooseText }}</p>
                                            @elseif ($sentence)
                                                <p class="text-meta leading-[1.45] text-ink-600" data-sentence>{{ $sentence }}</p>
                                            @endif

                                            @if ($hasWhy || $guide->isNotEmpty())
                                                <button type="button" x-on:click="why = ! why" x-bind:aria-expanded="why" aria-controls="why-{{ $criterion->id }}" data-why-toggle
                                                        class="interactive -ml-1 inline-flex items-center rounded-pill px-2.5 py-1 text-label font-bold text-periwinkle-link hover:bg-periwinkle-soft">
                                                    @if ($hasWhy)
                                                        <span x-text="why ? 'Hide why' : 'Why?'">Why?</span>
                                                    @else
                                                        <span x-text="why ? 'Hide level guide' : 'Level guide'">Level guide</span>
                                                    @endif
                                                </button>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex min-w-0 flex-col gap-2" data-controls>
                                        {{-- The suggested level is already selected. A hidden empty value lets an unticked row clear a saved choice. --}}
                                        <input type="hidden" name="levels[{{ $criterion->id }}]" value="">
                                        <div class="grid grid-cols-3 gap-2 max-[430px]:grid-cols-2 @[700px]:flex @[700px]:flex-nowrap @[700px]:justify-end @[700px]:gap-2.5"
                                             role="radiogroup" aria-label="Level for {{ $criterion->name }}" data-chips>
                                            @foreach ($row['levels'] as $level)
                                                <label class="flex cursor-pointer @[700px]:min-w-[110px] @[700px]:max-w-[150px] @[700px]:flex-[0_1_130px]" data-chip>
                                                    <input type="radio" class="cs-chip-input sr-only" name="levels[{{ $criterion->id }}]" value="{{ $level->id }}" x-model="sel"
                                                           @checked((string) $selected === (string) $level->id)>
                                                    <span class="cs-chip flex min-h-[44px] w-full flex-col justify-center rounded-[14px] border px-3 py-2 text-meta">
                                                        <span class="font-bold leading-tight">{{ $level->label }}</span>
                                                        <span class="cs-chip__marks text-label font-medium">{{ \App\Models\RubricLevel::marksText($level->marks, $level->marks_range) }}</span>
                                                        @if ($baseId === $level->id)
                                                            <span class="cs-chip__tag text-micro font-bold uppercase tracking-eyebrow" data-suggested>Suggested</span>
                                                        @endif
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <x-input-error :messages="$errors->get('levels.'.$criterion->id)" />

                                {{-- At most one extra line: the AI's level when it differs, and a flag when it differs a lot. --}}
                                @if ($aiDiffers || $row['differs'])
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 @[700px]:justify-end">
                                        @if ($aiDiffers)
                                            <button type="button" x-on:click="sel = '{{ $ai->id }}'; dirty = true" data-ai-note
                                                    class="interactive rounded-pill bg-surface px-3 py-1 text-label font-semibold text-ink-600 ring-1 ring-surface-line hover:bg-surface-muted">
                                                AI's view: {{ $ai->label }}
                                            </button>
                                        @endif
                                        @if ($row['differs'])
                                            <span class="inline-flex items-center gap-1.5 rounded-pill bg-warning-bg px-2.5 py-1 text-label font-semibold text-warning-text" data-flag role="status">
                                                <x-icon name="alert" size="13" class="shrink-0" />
                                                More than one level apart. Worth a look.
                                            </span>
                                        @endif
                                    </div>
                                @endif

                                @if ($row['level'])
                                    <p class="text-label font-semibold text-success-text @[700px]:text-right" data-decided>
                                        Saved: {{ $row['level']->display() }}@if ($row['counts'] && $row['marks'] !== null && (float) $row['marks'] !== (float) $row['level']->marks), worth {{ \App\Support\Rubric\RubricScoring::trim($row['marks']) }} {{ (float) $row['marks'] === 1.0 ? 'mark' : 'marks' }} in this section @endif.
                                        @unless ($row['counts']) <span class="text-ink-600">It is not counted in the total.</span>@endunless
                                    </p>
                                @endif
                                    </div>
                                </div>

                                {{-- Needed when the choice is neither of the suggestions. --}}
                                <x-collapse open="has && sel !== '' && ! offered.includes(Number(sel))" data-reason-field>
                                <div class="flex flex-col gap-1.5 pt-1">
                                    <label for="reason-{{ $criterion->id }}" class="text-meta font-bold text-ink-700">Why does your choice differ from the suggestions?</label>
                                    <textarea id="reason-{{ $criterion->id }}" name="reasons[{{ $criterion->id }}]" rows="2" maxlength="1000"
                                              class="w-full rounded-[14px] border border-surface-line bg-surface px-3 py-2.5 text-meta text-ink focus:border-ink focus:ring-0">{{ old('reasons.'.$criterion->id, $row['choice']?->override_reason) }}</textarea>
                                    <x-input-error :messages="$errors->get('reasons.'.$criterion->id)" />
                                </div>
                                </x-collapse>
                                {{-- The opened detail spans the whole row, under both columns. --}}
                                @if ($hasWhy || $guide->isNotEmpty())
                                    @if ($hasWhy)
                                        <x-collapse open="why" id="why-{{ $criterion->id }}" data-why>
                                        <div class="mt-2 flex flex-col gap-2.5">
                                        @if ($criterion->isSuggested() && $sug && is_array($sug->facts))
                                            <p class="text-label leading-[1.45] text-ink-400" data-facts>
                                                <span class="font-bold text-ink-600">Records:</span>
                                                {{ \App\Support\Marking\EvidenceFacts::describe($criterion->evidence_type, $sug->facts) }}
                                            </p>
                                        @endif

                                        @if ($criterion->isSuggested())
                                            <div class="grid gap-2.5 sm:grid-cols-2" data-suggestions>
                                                <div class="rounded-[14px] border border-surface-line bg-surface p-3" data-rule-based>
                                                    <p class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">From the numbers</p>
                                                    @if ($row['insufficient'])
                                                        <p class="mt-1 text-meta font-semibold text-ink-600">Too little group activity to compare</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ $sug->needs_input_reason }}</p>
                                                    @elseif ($row['baseline_level'])
                                                        <p class="mt-1 text-meta font-bold">{{ $row['baseline_level']->display() }}</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ \App\Support\Marking\EvidenceFacts::sentence($criterion->evidence_type, (array) $sug->facts) ?? $baselineNote }}</p>
                                                        <p class="text-label text-ink-300" data-level-position>{{ $levelPosition }}</p>
                                                    @elseif ($sug?->needs_input)
                                                        <p class="mt-1 text-meta font-semibold text-ink-600">You choose this one</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ $sug->needs_input_reason }}</p>
                                                    @else
                                                        <p class="mt-1 text-meta text-ink-400">No suggestion yet. Use Refresh suggestions on the group page.</p>
                                                    @endif
                                                </div>

                                                <div class="rounded-[14px] border border-surface-line bg-surface p-3" data-ai>
                                                    <p class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">AI's view</p>
                                                    @if ($ai)
                                                        <p class="mt-1 text-meta font-bold">{{ $ai->display() }}</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ $sug->ai_rationale }}</p>
                                                    @elseif ($row['insufficient'])
                                                        <p class="mt-1 text-meta text-ink-400">Not asked: there is too little activity in this group to compare students.</p>
                                                    @elseif ($sug?->needs_input)
                                                        <p class="mt-1 text-meta text-ink-400">Not asked: nothing recorded to base it on.</p>
                                                    @else
                                                        <p class="mt-1 text-meta text-ink-400">
                                                            Not available.
                                                            @if ($sug?->ai_message) {{ $sug->ai_message }}@elseif ($sug) Not requested.@endif
                                                        </p>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        <p class="text-label leading-[1.45] text-ink-600" x-show="sel !== '' && descriptions[sel]" data-level-meaning>
                                            <span class="font-bold">This level means:</span> <span x-text="descriptions[sel]"></span>
                                        </p>
                                    </div>
                                        </x-collapse>
                                    @else
                                        <x-collapse open="why" id="why-{{ $criterion->id }}" data-guide>
                                        <div class="mt-2 flex flex-col gap-1.5">
                                                @foreach ($guide as $level)
                                                    <p class="text-label leading-[1.45] text-ink-600">
                                                        <span class="font-bold text-ink">{{ $level->label }}</span>
                                                        <span class="text-ink-400">({{ \App\Models\RubricLevel::marksText($level->marks, $level->marks_range) }})</span>:
                                                        {{ $level->descriptor }}
                                                    </p>
                                                @endforeach
                                            </div>
                                        </x-collapse>
                                    @endif
                                @endif
                            </article>
                        @endforeach
                        </div>
                    </section>
                @endforeach
            </form>

            <div class="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-panel border border-surface-strong bg-surface/95 p-3 shadow-card backdrop-blur">
                <x-button type="submit" form="marking-form">Save choices</x-button>

                <form method="POST" action="{{ route('lecturer.marking.approve', [$rubric, $group, $student]) }}">
                    @csrf
                    <x-button variant="secondary" type="submit" :disabled="! $sheet['complete']" x-bind:disabled="dirty || {{ $sheet['complete'] ? 'false' : 'true' }}" data-approve>Approve marks</x-button>
                </form>

                <span class="w-full text-meta text-ink-400 sm:w-auto sm:min-w-0 sm:flex-1" data-approve-note>
                    @if (! $sheet['complete'])
                        Approval is blocked until every criterion has a level you chose and saved.
                    @else
                        <x-fade tag="span" :leave="false" x-show="dirty" x-cloak>You have unsaved changes. Save them before approving.</x-fade>
                        <x-fade tag="span" :leave="false" x-show="! dirty">Approving uses your saved choices and freezes them as the marks.</x-fade>
                    @endif
                </span>
            </div>
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
