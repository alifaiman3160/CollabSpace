<x-layouts.app title="Review rubric — CollabSpace"
               heading="Review the rubric"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('lecturer.rubrics', $subject)"
               back-label="Rubrics">

    @php
        $field = 'w-full rounded-[14px] border border-surface-line bg-surface px-3 py-2.5 text-meta text-ink focus:border-ink focus:ring-0';
        $modes = ['suggested' => 'System-suggested', 'manual' => 'Manual'];
        $criteriaTotal = collect($sections)->sum(fn ($s) => count($s['criteria'] ?? []));
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-[18px]">

        <x-flash />

        {{-- The one reminder that this is a draft. --}}
        <div class="flex items-center gap-3 rounded-card border border-periwinkle-deep bg-periwinkle-soft px-5 py-4">
            <x-icon name="eye" size="18" class="shrink-0 text-periwinkle-link" />
            <p class="min-w-0 flex-1 text-body font-bold text-ink">
                Nothing is saved yet.
                <span class="font-medium text-ink-600">
                    Check what was read from {{ $source ?? 'the file' }}, correct anything that is wrong, then save it as version {{ $nextVersion }}@if ($assessment && $assessment->subject->assessments->count() > 1) of the rubric for {{ $assessment->title }}@endif.
                </span>
            </p>
        </div>

        @if ($errors->any())
            <div class="flex flex-col gap-1 rounded-card bg-danger-bg px-5 py-3.5" role="alert">
                <p class="text-body font-bold text-danger-text">The rubric was not saved. Please correct the following.</p>
                <ul class="list-disc pl-5 text-meta text-danger-text">
                    @foreach (collect($errors->all())->unique()->take(8) as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (count($warnings) > 0)
            <details class="rounded-card border border-surface-line bg-surface-soft px-5 py-3.5">
                <summary class="flex cursor-pointer list-none items-center gap-2.5 text-meta font-bold text-ink-600">
                    <x-icon name="alert" size="16" class="shrink-0 text-ink-300" />
                    {{ count($warnings) }} {{ Str::plural('thing', count($warnings)) }} to look at
                </summary>
                <ul class="mt-3 flex list-disc flex-col gap-1 pl-5 text-meta leading-[1.5] text-ink-400">
                    @foreach (array_slice($warnings, 0, 40) as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </details>
        @endif

        <form method="POST" action="{{ route('lecturer.rubrics.confirm', [$subject, $token]) }}" class="flex flex-col gap-[18px]">
            @csrf

            <div class="flex flex-col gap-2 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle">
                <x-input-label for="name" value="Rubric name" />
                <x-text-input id="name" name="name" type="text" maxlength="120" required :value="$name" />
                <x-input-error :messages="$errors->get('name')" />
            </div>

            @foreach ($sections as $s => $section)
                @php
                    $maxima = \App\Support\Rubric\RubricScoring::maxima($section['criteria'] ?? []);
                    $declared = \App\Support\Rubric\RubricScoring::number($section['declared_total'] ?? null);
                    $formula = $section['scoring'] ?? null;
                    $formula = in_array($formula, \App\Support\Rubric\RubricScoring::FORMULAS, true) ? $formula : null;
                    $problem = \App\Support\Rubric\RubricScoring::problem($formula, $declared, $maxima, $section['title'] ?? 'This section');
                    $chosenMax = \App\Support\Rubric\RubricScoring::maximum($formula, $maxima);
                @endphp
                <section data-section
                         x-data="rubricSection(@js(['mode' => $section['mode'] ?? 'manual', 'scoring' => $formula, 'declared' => $declared === null ? '' : \App\Support\Rubric\RubricScoring::trim($declared), 'weighted' => $maxima['level_x_weight'], 'only' => $maxima['level_only']]))"
                         x-on:input="recalc()"
                         x-on:click="$nextTick(() => recalc())"
                         class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle">

                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex min-w-[220px] flex-1 flex-col gap-1.5">
                            <label for="section-{{ $s }}-title" class="text-meta font-bold text-ink-700">Section</label>
                            <input id="section-{{ $s }}-title" type="text" name="sections[{{ $s }}][title]" maxlength="150" required
                                   value="{{ $section['title'] ?? '' }}" class="{{ $field }} !text-nav font-bold">
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="section-{{ $s }}-mode" class="text-meta font-bold text-ink-700">Who decides this section</label>
                            <select id="section-{{ $s }}-mode" name="sections[{{ $s }}][mode]" x-model="mode"
                                    x-on:change="$el.closest('[data-section]').querySelectorAll('[data-criterion-mode]').forEach(select => { select.value = mode; select.dispatchEvent(new Event('change')) })"
                                    class="{{ $field }}">
                                @foreach ($modes as $value => $label)
                                    <option value="{{ $value }}" @selected(($section['mode'] ?? 'manual') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <button type="button" x-on:click="$el.closest('[data-section]').remove()"
                                class="interactive inline-flex items-center gap-1.5 rounded-pill px-3 py-2.5 text-meta font-semibold text-ink-400 hover:bg-danger-bg hover:text-danger-text focus-visible:bg-danger-bg focus-visible:text-danger-text"
                                aria-label="Delete the section {{ $section['title'] ?? '' }}">
                            <x-icon name="trash" size="15" /> Delete section
                        </button>
                    </div>

                    <p class="-mt-2 text-label text-ink-400">
                        <span x-show="mode === 'suggested'" x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to">The system may suggest a level for each criterion from recorded activity; you decide.</span>
                        <span x-show="mode === 'manual'" x-cloak x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to">You choose every level yourself. The system offers nothing here.</span>
                        Changing this sets every criterion below; each can then be changed on its own.
                    </p>

                    {{-- How this section turns a level into marks. The weight column means different
                         things in different tables, so this is chosen per section and shown against
                         the total the section declares. --}}
                    <div class="flex flex-col gap-3 rounded-card border border-surface-line bg-surface-soft p-4" data-scoring>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="flex w-36 flex-col gap-1.5">
                                <label for="section-{{ $s }}-total" class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Declared total</label>
                                <input id="section-{{ $s }}-total" type="text" inputmode="decimal" name="sections[{{ $s }}][declared_total]"
                                       x-model="declared" placeholder="—" value="{{ $declared === null ? '' : \App\Support\Rubric\RubricScoring::trim($declared) }}"
                                       class="{{ $field }} tabular-nums">
                            </div>

                            <div class="flex min-w-[240px] flex-1 flex-col gap-1.5">
                                <label for="section-{{ $s }}-scoring" class="text-label font-bold uppercase tracking-eyebrow text-ink-300">How marks are calculated</label>
                                <select id="section-{{ $s }}-scoring" name="sections[{{ $s }}][scoring]" x-model="scoring" class="{{ $field }}">
                                    <option value="" @selected($formula === null)>Choose…</option>
                                    <option value="{{ \App\Support\Rubric\RubricScoring::LEVEL_X_WEIGHT }}" @selected($formula === \App\Support\Rubric\RubricScoring::LEVEL_X_WEIGHT)>Level × weight</option>
                                    <option value="{{ \App\Support\Rubric\RubricScoring::LEVEL_ONLY }}" @selected($formula === \App\Support\Rubric\RubricScoring::LEVEL_ONLY)>Level only (the weight is the maximum)</option>
                                </select>
                            </div>
                        </div>

                        <dl class="grid gap-2 text-meta sm:grid-cols-3">
                            <div class="flex flex-col">
                                <dt class="text-label font-semibold text-ink-400">Maximum with level × weight</dt>
                                <dd class="font-bold tabular-nums" x-text="format(weighted)">{{ $maxima['level_x_weight'] === null ? '—' : \App\Support\Rubric\RubricScoring::trim($maxima['level_x_weight']) }}</dd>
                            </div>
                            <div class="flex flex-col">
                                <dt class="text-label font-semibold text-ink-400">Maximum with level only</dt>
                                <dd class="font-bold tabular-nums" x-text="format(only)">{{ \App\Support\Rubric\RubricScoring::trim($maxima['level_only']) }}</dd>
                            </div>
                            <div class="flex flex-col">
                                <dt class="text-label font-semibold text-ink-400">Maximum with the chosen formula</dt>
                                <dd class="font-bold tabular-nums" x-text="format(maximum)">{{ $chosenMax === null ? '—' : \App\Support\Rubric\RubricScoring::trim($chosenMax) }}</dd>
                            </div>
                        </dl>

                        <p data-scoring-status role="status"
                           class="flex items-start gap-2 rounded-[12px] px-3 py-2 text-meta font-semibold"
                           :class="status.tone === 'warn' ? 'bg-warning-bg text-warning-text' : 'bg-success-bg text-success-text'">
                            <x-icon name="alert" size="15" class="mt-0.5 shrink-0" />
                            <span x-text="status.text">{{ $problem ?? ('The maximum of '.\App\Support\Rubric\RubricScoring::trim($chosenMax).' matches the declared total.') }}</span>
                        </p>

                        <p class="text-label leading-[1.5] text-ink-400" x-show="mode === 'suggested' && scoring === ''">
                            A section the system suggests for cannot be saved until this is chosen.
                        </p>
                        <x-input-error :messages="$errors->get('sections.'.$s.'.scoring')" />
                    </div>

                    <p class="text-label leading-[1.5] text-ink-400" data-evidence-note>
                        Use Manual for criteria about attitude or quality, such as respect or the value of ideas. Recorded activity cannot measure these.
                    </p>

                    @foreach (($section['criteria'] ?? []) as $c => $criterion)
                        @php $base = "sections[{$s}][criteria][{$c}]"; @endphp

                        <div data-criterion
                             x-data="{ cmode: @js($criterion['mode'] ?? 'manual'), name: @js($criterion['name'] ?? '') }"
                             class="flex flex-col gap-3 rounded-card border border-surface-line bg-surface-soft p-4 lg:grid lg:grid-cols-[18rem_minmax(0,1fr)] lg:items-start lg:gap-4">
                            <div class="flex min-w-0 flex-col gap-3">
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="flex min-w-0 flex-[1_1_100%] flex-col gap-1.5">
                                    <label class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Criterion</label>
                                    <input type="text" name="{{ $base }}[name]" maxlength="255" required x-model="name"
                                           value="{{ $criterion['name'] ?? '' }}" class="{{ $field }} font-semibold">
                                </div>

                                {{-- What recorded activity a system-suggested criterion looks at. --}}
                                <div class="min-w-0 flex-[1_1_100%]" x-show="cmode === 'suggested'" x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to" x-transition:leave="cs-t-leave" x-transition:leave-start="cs-t-to" x-transition:leave-end="cs-t-from">
                                    <x-evidence-picker :name="$base.'[evidence_type]'"
                                                       :selected="$criterion['evidence_type'] ?? null"
                                                       :criterion-name="$criterion['name'] ?? ''"
                                                       :mode="$criterion['mode'] ?? 'manual'"
                                                       :id-prefix="'evidence-'.$s.'-'.$c" />
                                </div>

                                <div class="flex w-24 flex-col gap-1.5">
                                    <label class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Weight</label>
                                    <input type="text" inputmode="decimal" name="{{ $base }}[weight]"
                                           value="{{ $criterion['weight'] ?? '' }}" placeholder="—" class="{{ $field }} tabular-nums">
                                </div>

                                <div class="flex min-w-[160px] flex-1 flex-col gap-1.5">
                                    <label class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Decided by</label>
                                    <select name="{{ $base }}[mode]" data-criterion-mode x-model="cmode" class="{{ $field }}">
                                        @foreach ($modes as $value => $label)
                                            <option value="{{ $value }}" @selected(($criterion['mode'] ?? 'manual') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>


                                <button type="button" x-on:click="$el.closest('[data-criterion]').remove()"
                                        class="interactive inline-flex items-center gap-1.5 rounded-pill px-3 py-2.5 text-meta font-semibold text-ink-400 hover:bg-danger-bg hover:text-danger-text focus-visible:bg-danger-bg focus-visible:text-danger-text"
                                        aria-label="Delete the criterion {{ $criterion['name'] ?? '' }}">
                                    <x-icon name="trash" size="15" /> Delete
                                </button>
                            </div>

                            <p data-weight-flag role="status" @unless ($formula === \App\Support\Rubric\RubricScoring::LEVEL_ONLY && \App\Support\Rubric\RubricChecks::topLevelDiffersFromWeight($criterion)) hidden @endunless
                               class="flex items-start gap-2 rounded-[12px] bg-warning-bg px-3 py-2 text-meta font-semibold text-warning-text">
                                <x-icon name="alert" size="15" class="mt-0.5 shrink-0" />
                                <span data-weight-flag-text>{{ $formula === \App\Support\Rubric\RubricScoring::LEVEL_ONLY && \App\Support\Rubric\RubricChecks::topLevelDiffersFromWeight($criterion) ? \App\Support\Rubric\RubricChecks::topLevelMessage($criterion) : '' }}</span>
                            </p>
                            </div>

                            <div class="grid gap-2.5">
                                @foreach (($criterion['levels'] ?? []) as $l => $level)
                                    @php $lb = "{$base}[levels][{$l}]"; @endphp

                                    <div data-level class="flex flex-col gap-2 rounded-[14px] border border-surface-line bg-surface p-3">
                                        <div class="flex flex-wrap items-end gap-2">
                                            <div class="flex min-w-0 flex-[1_1_100%] flex-col gap-1 sm:flex-[1_1_11rem]">
                                                <label class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">Level</label>
                                                <input type="text" name="{{ $lb }}[label]" maxlength="60" required
                                                       value="{{ $level['label'] ?? '' }}" class="{{ $field }} font-semibold">
                                            </div>
                                            <div class="flex w-20 flex-col gap-1">
                                                <label class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">Marks</label>
                                                <input type="text" inputmode="decimal" name="{{ $lb }}[marks]" required
                                                       value="{{ $level['marks'] ?? '' }}" class="{{ $field }} tabular-nums">
                                            </div>
                                            <div class="flex w-20 flex-col gap-1">
                                                <label class="text-tiny font-bold uppercase tracking-eyebrow text-ink-300">Range</label>
                                                <input type="text" name="{{ $lb }}[range]" maxlength="20"
                                                       value="{{ $level['range'] ?? '' }}" placeholder="—" class="{{ $field }} tabular-nums">
                                            </div>
                                            <button type="button" x-on:click="$el.closest('[data-level]').remove()"
                                                    class="interactive mb-0.5 flex size-9 shrink-0 items-center justify-center rounded-pill text-ink-400 hover:bg-danger-bg hover:text-danger-text focus-visible:bg-danger-bg focus-visible:text-danger-text"
                                                    aria-label="Delete the level {{ $level['label'] ?? '' }}">
                                                <x-icon name="trash" size="15" />
                                            </button>
                                        </div>

                                        <textarea name="{{ $lb }}[descriptor]" rows="3" maxlength="2000"
                                                  aria-label="Description of {{ $level['label'] ?? 'this level' }}"
                                                  class="{{ $field }} leading-[1.5]">{{ $level['descriptor'] ?? '' }}</textarea>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>
            @endforeach

            <p class="text-label leading-[1.5] text-ink-400">
                The marks are what a level is worth in the calculation. Where the file gave a range such as 4–5, the
                upper figure is used and the range is kept for display. {{ $criteriaTotal }}
                {{ Str::plural('criterion', $criteriaTotal) }} in {{ count($sections) }} {{ Str::plural('section', count($sections)) }}.
            </p>

            <div class="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-panel border border-surface-strong bg-surface/95 p-3 shadow-card backdrop-blur">
                <x-button type="submit">Save as version {{ $nextVersion }}</x-button>
                <span class="text-meta text-ink-400">Earlier versions stay as they are.</span>
            </div>
        </form>

        <form method="POST" action="{{ route('lecturer.rubrics.discard', [$subject, $token]) }}">
            @csrf
            @method('DELETE')
            <x-button variant="secondary" type="submit">Discard this upload</x-button>
        </form>
    </div>

</x-layouts.app>
