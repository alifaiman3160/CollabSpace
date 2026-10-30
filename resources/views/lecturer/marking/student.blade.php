<x-layouts.app :title="$student->name.' — Marking — CollabSpace'"
               :heading="$student->name"
               :subtitle="$group->name.' — '.$rubric->name.', version '.$rubric->version"
               :back="route('lecturer.marking.group', [$rubric, $group])"
               :back-label="$group->name">

    <div class="flex flex-col gap-[18px]" x-data="{ dirty: false, confirm: null }"
         x-on:input="dirty = true" x-on:change="dirty = true">

        <x-flash />

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
        @endphp

        <section class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" data-top>
            <div class="flex flex-wrap items-stretch gap-3" data-totals>
                <div class="flex min-w-[140px] flex-col justify-center rounded-card bg-ink px-4 py-3 text-white" data-overall>
                    <span class="text-label font-bold uppercase tracking-eyebrow text-white/60">Total</span>
                    <span class="text-[30px] font-extrabold leading-none tracking-stat tabular-nums">
                        @if ($overall !== null){{ \App\Support\Rubric\RubricScoring::trim($overall) }}@else—@endif
                        @if ($sheet['maximum'] !== null)<span class="text-meta font-semibold text-white/60">of {{ \App\Support\Rubric\RubricScoring::trim($sheet['maximum']) }}</span>@endif
                    </span>
                    @if ($overall === null)
                        <span class="text-label font-medium text-white/70">Incomplete</span>
                    @endif
                </div>

                @foreach ($tiles as $tile)
                    <div class="flex min-w-[130px] flex-1 flex-col justify-center rounded-card bg-surface-soft px-4 py-3" data-tile>
                        <span class="truncate text-label font-bold uppercase tracking-eyebrow text-ink-400" title="{{ $tile['title'] }}">{{ \Illuminate\Support\Str::before($tile['title'], ' – ') }}</span>
                        <span class="text-[26px] font-extrabold leading-none tracking-stat tabular-nums">
                            @if ($tile['total'] !== null){{ \App\Support\Rubric\RubricScoring::trim($tile['total']) }}@else<span class="text-ink-300">—</span>@endif
                            @if ($tile['maximum'] !== null)<span class="text-meta font-semibold text-ink-400">of {{ \App\Support\Rubric\RubricScoring::trim($tile['maximum']) }}</span>@endif
                        </span>
                        @if ($tile['note'])<span class="text-label font-medium text-ink-400">{{ $tile['note'] }}</span>@endif
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                @unless ($approval)
                    <form id="accept-form" method="POST" action="{{ route('lecturer.marking.accept', [$rubric, $group, $student]) }}">@csrf</form>

                    <x-button type="submit" form="accept-form" icon="check" :disabled="$pendingAccept === 0"
                              x-bind:disabled="dirty || {{ $pendingAccept === 0 ? 'true' : 'false' }}" data-accept>
                        Accept suggestions @if ($pendingAccept > 0)({{ $pendingAccept }})@endif
                    </x-button>

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

            @unless ($approval)
                <p class="text-label leading-[1.5] text-ink-400" data-accept-note>
                    Accept suggestions saves the suggested level for each System-suggested criterion you have not decided yet. It never changes a level you chose and it does not approve anything.
                    @if ($pendingAccept === 0) There is nothing left to accept here.@endif
                </p>
            @endunless
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
                        @if ($sheet['scaled_pending'] === 0)
                            <span class="font-semibold">Marks so far: {{ \App\Support\Rubric\RubricScoring::trim($sheet['so_far']) }}@if ($sheet['maximum'] !== null) of {{ \App\Support\Rubric\RubricScoring::trim($sheet['maximum']) }}@endif.</span>
                        @else
                            <span class="block text-meta font-medium" data-scaled-pending>
                                No marks figure yet: {{ $sheet['scaled_pending'] === 1 ? 'a section is' : $sheet['scaled_pending'].' sections are' }} scaled from the System-suggested criteria,
                                and a part-way figure would look nearly final. It appears once all of those have a level.
                            </span>
                        @endif
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
                                    · scaled: {{ \App\Support\Rubric\RubricScoring::trim($section['scaling']['suggested_marks']) }} of {{ \App\Support\Rubric\RubricScoring::trim($section['scaling']['suggested_maximum']) }} from the System-suggested criteria × {{ \App\Support\Rubric\RubricScoring::trim($section['scaling']['section_maximum']) }} ÷ {{ \App\Support\Rubric\RubricScoring::trim($section['scaling']['suggested_maximum']) }}. Manual criteria are not counted.
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
                                        @if ($c['source'] === 'ai') Accepted the AI suggestion
                                        @elseif ($c['source'] === 'rule_based') Accepted the rule-based level
                                        @else Own choice @endif
                                    </x-table.stack>
                                    @if ($c['override_reason'])
                                        <p class="mt-1 text-label leading-[1.4] text-ink-600">Reason: {{ $c['override_reason'] }}</p>
                                    @endif
                                </td>
                                <td class="align-top text-label leading-[1.45] text-ink-400">
                                    @if ($c['insufficient_data'])
                                        Insufficient data: {{ $c['needs_input_reason'] }}
                                    @elseif ($c['baseline'] || $c['ai'])
                                        @if ($c['baseline'])<span class="block">Rule-based: {{ \App\Models\RubricLevel::describe($c['baseline']['label'], $c['baseline']['marks'], $c['baseline']['range']) }}</span>@endif
                                        @if ($c['ai'])<span class="block">AI-suggested: {{ \App\Models\RubricLevel::describe($c['ai']['label'], $c['ai']['marks'], $c['ai']['range']) }}</span>@endif
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
                            <div class="flex flex-col gap-2 rounded-card border border-surface-line bg-surface-soft p-4" data-method>
                                <label for="method-{{ $section->id }}" class="text-meta font-bold text-ink-700">How is this section totalled?</label>
                                <select id="method-{{ $section->id }}" name="methods[{{ $section->id }}]"
                                        class="w-full rounded-[14px] border border-surface-line bg-surface px-3 py-2.5 text-meta text-ink focus:border-ink focus:ring-0">
                                    <option value="">Choose…</option>
                                    <option value="add" @selected(old('methods.'.$section->id, $s['method']) === 'add')>Add the manual entries to the System-suggested ones</option>
                                    <option value="scale" @selected(old('methods.'.$section->id, $s['method']) === 'scale')>Score only the System-suggested criteria and scale to the section's full marks</option>
                                </select>
                                <x-input-error :messages="$errors->get('methods.'.$section->id)" />

                                <ul class="flex flex-col gap-1 text-label leading-[1.5] text-ink-400" data-method-help>
                                    <li><span class="font-bold text-ink-600">Add:</span> every criterion counts, the manual ones included. Each needs a level, and the total is all their marks added together.</li>
                                    <li><span class="font-bold text-ink-600">Scale:</span> only the System-suggested criteria count. Their marks are scaled to the section's full marks. The manual criteria do <span class="font-bold text-ink-600">not</span> count towards the total and need no level.</li>
                                </ul>

                                @if ($s['method_defaulted'])
                                    <p class="text-meta text-ink-600" data-method-default>
                                        Add is chosen for you because this section is marked on level marks alone. Change it if you prefer to scale; it is saved when you save.
                                    </p>
                                @endif

                                @if ($s['scaled'])
                                    <p class="text-meta font-semibold text-ink-600" data-scale-note>
                                        Scale is chosen: the manual criteria below are not counted in this section's total.
                                    </p>
                                @endif

                                @if ($s['scaling'])
                                    <p class="text-meta text-ink-600" data-scaling>
                                        Scaled: {{ \App\Support\Rubric\RubricScoring::trim($s['scaling']['suggested_marks']) }} of {{ \App\Support\Rubric\RubricScoring::trim($s['scaling']['suggested_maximum']) }}
                                        from the System-suggested criteria, × {{ \App\Support\Rubric\RubricScoring::trim($s['scaling']['section_maximum']) }} ÷ {{ \App\Support\Rubric\RubricScoring::trim($s['scaling']['suggested_maximum']) }}
                                        = {{ \App\Support\Rubric\RubricScoring::trim(round($s['scaling']['suggested_marks'] * $s['scaling']['factor'], 2)) }}.
                                    </p>
                                @endif
                            </div>
                        @endif

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

                                // The one sentence on the row. The fuller wording is under "Why?".
                                $sentence = match (true) {
                                    ! $row['counts'] => null,
                                    $row['baseline_level'] !== null && $sug !== null && is_array($sug->facts)
                                        => \App\Support\Marking\EvidenceFacts::sentence($criterion->evidence_type, $sug->facts) ?? $sug->baseline_note,
                                    $row['baseline_level'] !== null => $sug?->baseline_note,
                                    default => null,
                                };
                                $youChoose = $row['counts'] && ($row['baseline_level'] === null);
                            @endphp

                            <article class="flex flex-col gap-2.5 rounded-card border border-surface-line bg-surface-soft p-4"
                                     data-criterion-id="{{ $criterion->id }}" @if (! $row['counts']) data-not-counted @endif
                                     x-data="{ sel: @js((string) ($selected ?? '')), offered: @js($offered), has: @js($offered !== []), descriptions: @js($descriptions), why: false }">

                                <div class="flex flex-col gap-0.5">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                        <h3 class="text-nav font-bold">{{ $criterion->name }}</h3>
                                        <span class="text-label text-ink-400">
                                            {{ $criterion->isSuggested() ? 'System-suggested' : 'Manual' }}
                                            @if ($criterion->weight !== null) · weight {{ rtrim(rtrim($criterion->weight, '0'), '.') }}@endif
                                            @unless ($row['counts']) · <span class="font-bold text-ink-600">not counted</span>@endunless
                                        </span>
                                    </div>

                                    @if (! $row['counts'])
                                        <p class="text-meta leading-[1.45] text-ink-600" data-not-counted-note>
                                            Not counted in this section's total, so it needs no level. Choose one only if you want it on record.
                                        </p>
                                    @elseif ($youChoose)
                                        <p class="text-meta leading-[1.45] text-ink-600" data-you-choose>You choose this one.</p>
                                    @elseif ($sentence)
                                        <p class="text-meta leading-[1.45] text-ink-600" data-sentence>{{ $sentence }}</p>
                                    @endif
                                </div>

                                {{-- Five chips at most; the suggested one is already selected. A hidden empty value lets an unticked row clear a saved choice. --}}
                                <input type="hidden" name="levels[{{ $criterion->id }}]" value="">
                                <div class="flex flex-wrap gap-2.5 pt-1" role="radiogroup" aria-label="Level for {{ $criterion->name }}" data-chips>
                                    @foreach ($row['levels'] as $level)
                                        <label class="relative cursor-pointer" data-chip>
                                            <input type="radio" class="peer sr-only" name="levels[{{ $criterion->id }}]" value="{{ $level->id }}" x-model="sel"
                                                   @checked((string) $selected === (string) $level->id)>
                                            <span class="flex min-w-[92px] flex-col rounded-[14px] border border-surface-line bg-surface px-3 py-2 text-meta
                                                         transition-colors duration-motion ease-motion hover:bg-surface-muted
                                                         peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white
                                                         peer-focus-visible:ring-2 peer-focus-visible:ring-periwinkle-link peer-focus-visible:ring-offset-2">
                                                <span class="font-bold leading-tight">{{ $level->label }}</span>
                                                <span class="text-label font-medium opacity-70">{{ \App\Models\RubricLevel::marksText($level->marks, $level->marks_range) }}</span>
                                            </span>
                                            @if ($baseId === $level->id)
                                                <span class="pointer-events-none absolute -top-2 right-2 rounded-pill border border-periwinkle-deep bg-periwinkle-soft px-1.5 text-micro font-bold uppercase tracking-eyebrow text-periwinkle-link" data-suggested>Suggested</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                                <x-input-error :messages="$errors->get('levels.'.$criterion->id)" />

                                {{-- At most one extra line: the AI's level when it differs, and a flag when it differs a lot. --}}
                                @if ($aiDiffers || $row['differs'])
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                        @if ($aiDiffers)
                                            <button type="button" x-on:click="sel = '{{ $ai->id }}'; dirty = true" data-ai-note
                                                    class="interactive rounded-pill bg-surface px-3 py-1 text-label font-semibold text-ink-600 ring-1 ring-surface-line hover:bg-surface-muted">
                                                AI suggests {{ $ai->label }}
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
                                    <p class="text-label font-semibold text-success-text" data-decided>
                                        Saved: {{ $row['level']->display() }}@if ($row['counts'] && $row['marks'] !== null && (float) $row['marks'] !== (float) $row['level']->marks), worth {{ \App\Support\Rubric\RubricScoring::trim($row['marks']) }} {{ (float) $row['marks'] === 1.0 ? 'mark' : 'marks' }} in this section @endif.
                                        @unless ($row['counts']) <span class="text-ink-600">It is not counted in the total.</span>@endunless
                                    </p>
                                @endif

                                {{-- Needed when the choice is neither of the suggestions. --}}
                                <div class="flex flex-col gap-1.5" x-show="has && sel !== '' && ! offered.includes(Number(sel))" data-reason-field>
                                    <label for="reason-{{ $criterion->id }}" class="text-meta font-bold text-ink-700">Why does your choice differ from the suggestions?</label>
                                    <textarea id="reason-{{ $criterion->id }}" name="reasons[{{ $criterion->id }}]" rows="2" maxlength="1000"
                                              class="w-full rounded-[14px] border border-surface-line bg-surface px-3 py-2.5 text-meta text-ink focus:border-ink focus:ring-0">{{ old('reasons.'.$criterion->id, $row['choice']?->override_reason) }}</textarea>
                                    <x-input-error :messages="$errors->get('reasons.'.$criterion->id)" />
                                </div>

                                {{-- The detail, folded away. --}}
                                <div>
                                    <button type="button" x-on:click="why = ! why" x-bind:aria-expanded="why" aria-controls="why-{{ $criterion->id }}" data-why-toggle
                                            class="interactive inline-flex items-center gap-1 rounded-pill px-2.5 py-1 text-label font-bold text-periwinkle-link hover:bg-periwinkle-soft">
                                        <span x-text="why ? 'Hide why' : 'Why?'">Why?</span>
                                    </button>

                                    <div id="why-{{ $criterion->id }}" x-show="why" x-cloak class="mt-2 flex flex-col gap-2.5" data-why>
                                        @if ($criterion->isSuggested() && $sug && is_array($sug->facts))
                                            <p class="text-label leading-[1.45] text-ink-400" data-facts>
                                                <span class="font-bold text-ink-600">Facts:</span>
                                                {{ \App\Support\Marking\EvidenceFacts::describe($criterion->evidence_type, $sug->facts) }}
                                            </p>
                                        @endif

                                        @if ($criterion->isSuggested())
                                            <div class="grid gap-2.5 sm:grid-cols-2" data-suggestions>
                                                <div class="rounded-[14px] border border-surface-line bg-surface p-3" data-rule-based>
                                                    <p class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">Rule-based</p>
                                                    @if ($row['insufficient'])
                                                        <p class="mt-1 text-meta font-semibold text-ink-600">Not enough group activity to compare</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ $sug->needs_input_reason }}</p>
                                                    @elseif ($row['baseline_level'])
                                                        <p class="mt-1 text-meta font-bold">{{ $row['baseline_level']->display() }}</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ $sug->baseline_note }}</p>
                                                    @elseif ($sug?->needs_input)
                                                        <p class="mt-1 text-meta font-semibold text-ink-600">You choose this one</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ $sug->needs_input_reason }}</p>
                                                    @else
                                                        <p class="mt-1 text-meta text-ink-400">No suggestion yet. Use Refresh suggestions on the group page.</p>
                                                    @endif
                                                </div>

                                                <div class="rounded-[14px] border border-surface-line bg-surface p-3" data-ai>
                                                    <p class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">AI-suggested</p>
                                                    @if ($ai)
                                                        <p class="mt-1 text-meta font-bold">{{ $ai->display() }}</p>
                                                        <p class="text-label leading-[1.45] text-ink-400">{{ $sug->ai_rationale }}</p>
                                                    @elseif ($row['insufficient'])
                                                        <p class="mt-1 text-meta text-ink-400">Not asked: too little group activity to compare students.</p>
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
                                </div>
                            </article>
                        @endforeach
                    </section>
                @endforeach
            </form>

            <div class="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-panel border border-surface-strong bg-surface/95 p-3 shadow-card backdrop-blur">
                <x-button type="submit" form="marking-form">Save choices</x-button>

                <form method="POST" action="{{ route('lecturer.marking.approve', [$rubric, $group, $student]) }}">
                    @csrf
                    <x-button variant="secondary" type="submit" :disabled="! $sheet['complete']" x-bind:disabled="dirty || {{ $sheet['complete'] ? 'false' : 'true' }}" data-approve>Approve marks</x-button>
                </form>

                <span class="min-w-0 flex-1 text-meta text-ink-400" data-approve-note>
                    @if (! $sheet['complete'])
                        Approval is blocked until every criterion has a level you chose and saved.
                    @else
                        <span x-show="dirty" x-cloak>You have unsaved changes. Save them before approving.</span>
                        <span x-show="! dirty">Approving uses your saved choices and freezes them as the marks.</span>
                    @endif
                </span>
            </div>
        @endif

        <x-confirm-dialog />
    </div>

</x-layouts.app>
