<x-layouts.app :title="$group->name.' — Marking — CollabSpace'"
               :heading="$group->name"
               :subtitle="$rubric->subject->code.($group->section ? ' · Section '.$group->section->name : '').' — '.$rubric->name.', version '.$rubric->version"
               :back="route('lecturer.marking')"
               back-label="Marking">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- Which assessment this is, beside the section; and a way to the same group's marking for the others. --}}
        <div class="flex flex-wrap items-center gap-2" data-marking-context>
            <x-assessment-chip :assessment="$rubric->assessment" />
            <x-section-chip :section="$group->section" :code="$rubric->subject->code" />
        </div>

        @if ($rubric->assessment)
            <x-lecturer.submission-card :group="$group" :assessment="$rubric->assessment" />
        @endif

        @if ($assessments->count() > 1)
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by assessment" data-assessment-filter>
                <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Assessment</span>
                @foreach ($assessments as $option)
                    @php $target = $currentRubrics[$option->id] ?? null; @endphp
                    @if ($target)
                        <a href="{{ route('lecturer.marking.group', [$target, $group]) }}" wire:navigate data-assessment-option="{{ $option->id }}"
                           @if ($option->id === $rubric->assessment_id) aria-current="page" @endif
                           class="interactive flex h-9 items-center rounded-pill border px-4 text-meta font-bold motion-colors {{ $option->id === $rubric->assessment_id ? 'border-ink bg-ink text-white' : 'border-surface-line bg-surface text-ink-600 hover:bg-surface-hover hover:text-ink' }}">{{ $option->title }}</a>
                    @else
                        <span class="flex h-9 items-center rounded-pill border border-surface-line bg-surface-soft px-4 text-meta font-bold text-ink-300" data-tooltip="No rubric for this assessment yet" data-assessment-option="{{ $option->id }}">{{ $option->title }}</span>
                    @endif
                @endforeach
            </div>
        @endif

        {{--
            What suggestions have to work with, and the two things to do for the whole group.

            Refreshing works through the students one request at a time (see
            marking-suggest.js), so the page shows how far it has got, and a student
            who cannot be finished can be retried without redoing the rest.
        --}}
        <div class="flex flex-col gap-[18px]"
             @if ($suggested)
             x-data="markingSuggest(@js([
                 'token' => csrf_token(),
                 'maxAttempts' => (int) config('marking.ai.web_max_attempts', 3),
                 'waitCap' => (int) config('marking.ai.retry_wait_cap_seconds', 60),
                 'students' => $rows->map(fn ($row) => [
                     'id' => $row['student']->id,
                     'name' => $row['student']->name,
                     'approved' => $row['approval'] !== null,
                     'url' => route('lecturer.marking.suggest', [$rubric, $group, $row['student']]),
                 ])->values()->all(),
             ]))"
             @endif>

            <section class="flex flex-wrap items-center gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle">
                <div class="flex min-w-[240px] flex-1 flex-col gap-1">
                    <h2 class="text-nav font-bold">Suggested levels</h2>
                    <p class="text-meta leading-[1.5] text-ink-400" data-ai-status>
                        @if ($suggested)
                            We suggest a level for each criterion from what every student did in the group. You make the final decision.
                        @else
                            No criterion in this rubric gets a suggestion, so every level is yours to choose.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    @if ($suggested)
                        <x-button variant="secondary" type="button" x-on:click="start()" x-bind:disabled="running"
                                  x-bind:aria-busy="running" data-refresh>
                            <svg x-show="running" x-cloak class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25" />
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            </svg>
                            <x-icon name="chart" x-show="! running" />
                            <span x-text="running ? 'Refreshing…' : 'Refresh suggestions'">Refresh suggestions</span>
                        </x-button>
                    @endif

                    @if ($suggested)
                        <form method="POST" action="{{ route('lecturer.marking.accept_all', [$rubric, $group]) }}">
                            @csrf
                            <x-button type="submit" icon="check" data-accept-all
                                      title="Fills in the suggested level for every student who is not approved yet. It does not approve anything.">Use all suggestions</x-button>
                        </form>
                    @endif

                    <x-button variant="secondary" :href="route('lecturer.marking.export', [$rubric, $group])" icon="file">Download evidence report</x-button>
                </div>
            </section>

            @if ($suggested)
                {{-- Progress: one line per student, and a plain message for anything that went wrong. --}}
                <section x-show="started" x-cloak x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to" x-transition:leave="cs-t-leave" x-transition:leave-start="cs-t-to" x-transition:leave-end="cs-t-from" aria-live="polite" data-progress
                         class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <p class="text-nav font-bold" x-text="headline"></p>
                            <p class="text-label leading-[1.45] text-ink-400" x-show="running" x-cloak data-leave-note>If you leave, the students already done stay done. Refresh again to finish.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-meta font-semibold tabular-nums text-ink-400" x-text="finishedCount + ' of ' + total"></span>
                            <button type="button" x-show="running" x-on:click="stop()"
                                    class="interactive rounded-pill px-3 py-1.5 text-meta font-semibold text-ink-600 hover:bg-surface-muted focus-visible:bg-surface-muted">
                                Stop
                            </button>
                            <x-button variant="secondary" class="!px-4 !py-1.5 !text-meta" x-show="! running && finishedCount > 0"
                                      :href="route('lecturer.marking.group', [$rubric, $group])">Show the results</x-button>
                        </div>
                    </div>

                    <div class="h-1.5 w-full overflow-hidden rounded-pill bg-surface-muted" role="progressbar"
                         aria-label="Progress" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="percent">
                        <div class="h-full rounded-pill bg-ink" x-bind:style="'width: ' + percent + '%'"></div>
                    </div>

                    <ul class="flex flex-col divide-y divide-surface-line">
                        <template x-for="item in items" x-bind:key="item.id">
                            <li class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2.5" x-bind:data-state="item.state">
                                <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center">
                                    <svg x-show="item.state === 'working'" class="size-4 animate-spin text-ink-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25" />
                                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                    </svg>
                                    <span x-show="item.state === 'done'" class="size-2 rounded-pill bg-success" aria-hidden="true"></span>
                                    <span x-show="item.state === 'failed'" class="size-2 rounded-pill bg-danger" aria-hidden="true"></span>
                                    <span x-show="item.state === 'pausing'" class="size-2 rounded-pill bg-warning" aria-hidden="true"></span>
                                    <span x-show="item.state === 'waiting' || item.state === 'skipped'" class="size-2 rounded-pill bg-surface-strong" aria-hidden="true"></span>
                                </span>

                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="text-nav font-semibold" x-text="item.name"></span>

                                    <span class="text-label leading-[1.45] text-ink-400" x-show="item.state === 'waiting'">Waiting</span>
                                    <span class="text-label leading-[1.45] text-ink-400" x-show="item.state === 'working'"
                                          x-text="item.attempt > 1 ? 'Trying again (try ' + item.attempt + ')…' : 'Working on it…'"></span>
                                    <span class="text-label leading-[1.45] text-ink-400" x-show="item.state === 'skipped'" x-text="item.note"></span>
                                    <span class="text-label leading-[1.45] text-warning-text" x-show="item.state === 'pausing'" x-text="item.note"></span>
                                    <span class="text-label leading-[1.45] text-danger-text" x-show="item.state === 'failed'" x-text="item.note"></span>

                                    <span class="text-label leading-[1.45] text-ink-400" x-show="item.state === 'done'">
                                        <template x-if="item.counts">
                                            <span x-text="item.counts.ai + ' with the AI\'s view, ' + item.counts.rule_based + ' from the numbers only' + (item.counts.insufficient ? ', ' + item.counts.insufficient + ' without enough activity to compare' : '')"></span>
                                        </template>
                                        <span x-show="item.note" x-text="' ' + item.note"></span>
                                    </span>
                                </div>

                                <button type="button" x-show="item.state === 'failed' && ! running" x-on:click="retry(item)"
                                        class="interactive rounded-pill bg-surface-muted px-3 py-1.5 text-meta font-semibold text-ink hover:bg-surface-hover">
                                    Retry
                                </button>
                            </li>
                        </template>
                    </ul>
                </section>
            @endif
        </div>
        @if ($locked)
            <p class="flex items-start gap-2 text-label leading-[1.5] text-ink-400">
                <x-icon name="lock" size="14" class="mt-0.5 shrink-0 text-ink-300" />
                Some marks are already approved, so who decides each criterion, and what its suggestion is based on, can no longer be changed.
            </p>
        @endif

        <x-data-table :headings="['Student', 'Marking', ['Total', 'right'], ['Flags', 'right'], '']" min-width="760px"
                      empty="This group has no students yet.">
            @foreach ($rows as $row)
                @php
                    $sheet = $row['sheet'];
                    $student = $row['student'];
                @endphp
                <tr>
                    <td>
                        <x-table.person :name="$student->name">
                            <span class="truncate">{{ $student->matric_no ?? $student->email }}</span>
                        </x-table.person>
                    </td>

                    <td>
                        @if ($row['approval'])
                            <x-table.dot-pill tone="success">Approved</x-table.dot-pill>
                        @elseif ($sheet['complete'])
                            <x-table.dot-pill tone="warning">Ready to approve</x-table.dot-pill>
                        @elseif ($sheet['decided'] === 0)
                            <span class="text-meta text-ink-400">Not started</span>
                        @else
                            <span class="text-meta font-semibold tabular-nums text-ink-600">{{ $sheet['decided'] }} of {{ $sheet['criteria'] }} decided</span>
                        @endif
                    </td>

                    <td class="num">
                        @if ($sheet['total'] !== null)
                            <span class="text-nav font-bold tabular-nums" data-total>{{ \App\Support\Rubric\RubricScoring::trim($row['approval'] ? (float) $row['approval']->total : $sheet['total']) }}@if ($sheet['maximum'] !== null) <span class="font-medium text-ink-400">of {{ \App\Support\Rubric\RubricScoring::trim($sheet['maximum']) }}</span>@endif</span>
                        @else
                            <span class="text-meta text-ink-400" title="Not every criterion has a level yet" data-incomplete>incomplete</span>
                            @if ($sheet['scaled_pending'] === 0)
                                <span class="block text-label tabular-nums text-ink-300" data-so-far>{{ \App\Support\Rubric\RubricScoring::trim($sheet['so_far']) }}@if ($sheet['maximum'] !== null) of {{ \App\Support\Rubric\RubricScoring::trim($sheet['maximum']) }}@endif so far</span>
                            @endif
                        @endif
                    </td>

                    <td class="num">
                        @if ($row['flags'] > 0)
                            <x-table.dot-pill tone="warning">{{ $row['flags'] }}</x-table.dot-pill>
                        @else
                            <span class="text-meta text-ink-300">—</span>
                        @endif
                    </td>

                    <td>
                        <div class="flex justify-end">
                            <x-button class="!px-4 !py-2 !text-meta"
                                      :href="route('lecturer.marking.student', [$rubric, $group, $student])">
                                {{ $row['approval'] ? 'View' : 'Mark' }}
                            </x-button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-data-table>

        <p class="text-label leading-[1.5] text-ink-400">
            A total appears only when every criterion that counts has a level you chose. Until then it reads
            incomplete, with the marks so far, except where a section is scaled from its System-suggested criteria: that has no figure until they are all chosen. Flags count criteria where the two suggestions are more than one level apart.
        </p>
    </div>

</x-layouts.app>
