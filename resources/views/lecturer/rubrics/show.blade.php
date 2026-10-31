<x-layouts.app :title="'Rubric, version '.$rubric->version.' — CollabSpace'"
               :heading="$rubric->name"
               :subtitle="$subject->code.' — version '.$rubric->version"
               :back="route('lecturer.rubrics', $subject)"
               back-label="Rubrics">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-meta text-ink-400">
            @if ($rubric->is_current)
                <x-table.dot-pill tone="success">Current version</x-table.dot-pill>
            @else
                <x-table.dot-pill tone="neutral">Earlier version</x-table.dot-pill>
            @endif
            <span>Saved {{ $rubric->created_at->format('j M Y, g:ia') }}@if ($rubric->creator) by {{ $rubric->creator->name }}@endif</span>
            @if ($rubric->source_file)<span class="truncate">from {{ $rubric->source_file }}</span>@endif
        </div>

        @php
            $locked = $rubric->isLocked();
        @endphp

        @if ($locked)
            <div class="flex items-start gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-3.5" data-locked-notice>
                <x-icon name="lock" size="17" class="mt-0.5 shrink-0 text-ink-300" />
                <p class="text-meta leading-[1.55] text-ink-600">
                    <span class="font-bold">This version is locked.</span>
                    Marks have been approved against it, so who decides each criterion, and what its suggestion is based on, can no longer be changed here.
                    To change them, upload a new version, or revoke the approvals first.
                </p>
            </div>
        @endif

        <form method="POST" action="{{ route('lecturer.rubrics.evidence', [$subject, $rubric]) }}" class="flex flex-col gap-[18px]">
        @csrf
        @method('PUT')

        @foreach ($rubric->sections as $section)
            <section class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h2 class="text-h3 font-extrabold tracking-title">{{ $section->title }}</h2>
                    <x-badge :tone="$section->isSuggested() ? 'info' : 'neutral'">
                        {{ $section->isSuggested() ? 'System-suggested' : 'Manual' }}
                    </x-badge>
                </div>

                <p class="-mt-1 text-meta text-ink-400">{{ ucfirst($section->scoringExplained()) }}.</p>

                @if ($section->criteria->contains(fn ($c) => $c->isSuggested()))
                    <p class="text-label leading-[1.5] text-ink-400" data-evidence-note>
                        Use Manual for criteria about attitude or quality, such as respect or the value of ideas. Recorded activity cannot measure these.
                    </p>
                @endif

                <x-data-table :headings="['Criterion', ['Weight', 'right'], 'Performance levels']" min-width="760px">
                    @foreach ($section->criteria as $criterion)
                        <tr>
                            <td class="align-top"
                                x-data="{ cmode: @js($criterion->mode), name: @js($criterion->name) }">
                                <x-table.stack>
                                    <x-slot:primary>{{ $criterion->name }}</x-slot:primary>
                                    {{ $criterion->isSuggested() ? 'System-suggested' : 'Manual' }}
                                </x-table.stack>

                                {{-- Who decides this criterion. --}}
                                <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                    <label for="mode-{{ $criterion->id }}" class="text-label font-bold uppercase tracking-eyebrow text-ink-300">Decided by</label>
                                    <select id="mode-{{ $criterion->id }}" name="mode[{{ $criterion->id }}]" x-model="cmode" @disabled($locked)
                                            class="w-full min-w-0 rounded-[12px] border border-surface-line bg-surface px-3 py-2 text-meta text-ink focus:border-ink focus:ring-0 disabled:opacity-60 sm:w-auto sm:min-w-[11rem]">
                                        <option value="suggested" @selected($criterion->isSuggested())>System-suggested</option>
                                        <option value="manual" @selected(! $criterion->isSuggested())>Manual</option>
                                    </select>
                                </div>

                                @if ($criterion->isSuggested())
                                    <div class="mt-2" x-show="cmode === 'suggested'" x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to" x-transition:leave="cs-t-leave" x-transition:leave-start="cs-t-to" x-transition:leave-end="cs-t-from">
                                        <x-evidence-picker :name="'evidence['.$criterion->id.']'"
                                                           :selected="$criterion->evidence_type"
                                                           :criterion-name="$criterion->name"
                                                           mode="suggested"
                                                           :id-prefix="'evidence-'.$criterion->id"
                                                           :disabled="$locked" />
                                    </div>
                                @else
                                    <p class="mt-1.5 text-label leading-[1.4] text-ink-400" x-show="cmode === 'suggested'" x-cloak>
                                        Save to choose what this criterion's suggestions look at.
                                    </p>
                                @endif
                            </td>
                            <td class="num align-top text-meta font-semibold">
                                {{ $criterion->weight === null ? '—' : rtrim(rtrim($criterion->weight, '0'), '.') }}

                                @php
                                    $check = ['weight' => $criterion->weight, 'levels' => $criterion->levels->map(fn ($l) => ['marks' => $l->marks])->all()];
                                @endphp
                                @if ($section->scoring === \App\Support\Rubric\RubricScoring::LEVEL_ONLY && \App\Support\Rubric\RubricChecks::topLevelDiffersFromWeight($check))
                                    <p class="mt-1.5 flex max-w-[200px] items-start gap-1.5 text-left text-label font-semibold leading-[1.4] text-warning-text" data-weight-flag>
                                        <x-icon name="alert" size="13" class="mt-0.5 shrink-0" />
                                        {{ \App\Support\Rubric\RubricChecks::topLevelMessage($check) }}
                                    </p>
                                @endif
                            </td>

                            <td class="align-top">
                                <ul class="flex flex-col gap-1.5">
                                    @foreach ($criterion->levels as $level)
                                        <li class="flex flex-col gap-0.5 text-meta sm:flex-row sm:items-baseline sm:gap-2.5">
                                            <span class="font-bold sm:w-48 sm:shrink-0 sm:whitespace-nowrap">{{ $level->label }}
                                                <span class="font-semibold tabular-nums text-ink-400">({{ $level->marksLabel() }})</span>
                                            </span>
                                            <span class="min-w-0 text-ink-600">{{ $level->descriptor ?? '—' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </section>
        @endforeach

        @unless ($locked)
            <div class="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-panel border border-surface-strong bg-surface/95 p-3 shadow-card backdrop-blur">
                <x-button type="submit">Save changes</x-button>
                <span class="text-meta text-ink-400">This changes only who decides each criterion and what its suggestion is based on. The rubric's content is not edited.</span>
            </div>
        @endunless
        </form>
    </div>

</x-layouts.app>
