<x-layouts.app title="Students — CollabSpace"
               heading="Students"
               :subtitle="$hasGroups
                   ? $studentCount.' '.Str::plural('student', $studentCount).' across your subjects'
                       .($flaggedCount ? ' · '.$flaggedCount.' flagged for low participation.' : '.')
                   : 'No groups yet.'">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        @if (! $hasGroups)
            <x-empty-state
                icon="graduation"
                title="No students yet"
                body="Students appear here once you have a subject with a group and have assigned people to it."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            <x-subject-section-filter :filter="$filter" route="lecturer.students" />

            <form method="GET" action="{{ route('lecturer.students') }}" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="group" value="{{ $filterGroup?->id }}">
                <input type="hidden" name="subject" value="{{ $filterSubject?->id }}">
                <input type="hidden" name="section" value="{{ $filter['sectionId'] }}">

                {{-- A combobox: suggestions from the first letter, under the field and as wide as it. --}}
                <div class="relative min-w-[260px] flex-1" x-data="studentSearch" data-url="{{ route('lecturer.students.suggestions') }}"
                     x-on:click.outside="close()" data-student-search>
                    <label class="flex h-11 items-center gap-2.5 rounded-pill bg-surface-muted px-[18px]">
                        <x-icon name="search" size="17" class="text-ink-300" />
                        <span class="sr-only">Search students</span>
                        <input type="text" name="search" value="{{ $search }}" x-ref="input" x-model="query"
                               placeholder="Search name, matric no., group or subject"
                               autocomplete="off" spellcheck="false"
                               role="combobox" aria-autocomplete="list" aria-haspopup="listbox"
                               aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'"
                               aria-controls="student-suggestions"
                               x-bind:aria-activedescendant="active >= 0 ? optionId(active) : null"
                               x-on:input="onInput()" x-on:keydown="key($event)"
                               class="w-full min-w-0 border-none bg-transparent p-0 text-body text-ink outline-none placeholder:text-ink-300 focus:ring-0">
                        <button type="button" x-show="query !== ''" x-cloak x-on:click="clear()" aria-label="Clear the search text" data-tooltip="Clear"
                                class="interactive flex size-6 shrink-0 items-center justify-center rounded-pill text-ink-300 hover:bg-white hover:text-ink">
                            <x-icon name="plus" size="14" class="rotate-45" />
                        </button>
                    </label>

                    <div id="student-suggestions" role="listbox" aria-label="Suggestions" x-show="open" x-cloak
                         x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to"
                         x-transition:leave="cs-t-leave" x-transition:leave-start="cs-t-to" x-transition:leave-end="cs-t-from"
                         class="cs-suggest absolute inset-x-0 top-full z-30 mt-1.5 max-h-[360px] overflow-y-auto rounded-card border border-surface-line bg-surface p-1.5 shadow-card">
                        <template x-for="(item, index) in items" :key="item.type + '-' + (item.student_id ?? '') + '-' + (item.group_id ?? '') + '-' + (item.subject_id ?? '') + '-' + index">
                            <div role="option" x-bind:id="optionId(index)" x-bind:aria-selected="active === index ? 'true' : 'false'"
                                 x-on:mousedown.prevent="choose(item)" x-on:mousemove="active = index"
                                 class="flex cursor-pointer items-center gap-3 rounded-[10px] px-3 py-2 text-meta"
                                 x-bind:class="active === index ? 'bg-surface-muted' : 'hover:bg-surface-soft'">
                                <template x-if="item.type === 'student'">
                                    <span class="flex min-w-0 flex-1 items-center gap-3">
                                        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-pill bg-periwinkle text-label font-bold text-ink"
                                              x-text="item.name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('')"></span>
                                        <span class="flex min-w-0 flex-1 flex-col">
                                            <span class="truncate text-nav text-ink">
                                                <template x-for="piece in pieces(item.name)"><span x-text="piece.text" x-bind:class="piece.bold ? 'font-extrabold' : 'font-semibold'"></span></template>
                                            </span>
                                            <span class="truncate text-label text-ink-400" x-text="item.matric"></span>
                                        </span>
                                        <span class="max-w-[8rem] shrink-0 truncate rounded-pill bg-surface-muted px-2 py-0.5 text-label font-semibold text-ink-600" x-text="item.group" x-bind:title="item.group"></span>
                                        <span class="shrink-0 rounded-pill px-2 py-0.5 text-label font-extrabold text-ink" data-subject-chip
                                              x-bind:style="'background-color: ' + item.accent" x-bind:data-palette="item.palette" x-text="item.subject_code" x-bind:title="item.subject_name"></span>
                                    </span>
                                </template>
                                <template x-if="item.type !== 'student'">
                                    <span class="flex min-w-0 flex-1 items-center gap-3">
                                        <span class="shrink-0 rounded-pill bg-surface-muted px-2 py-0.5 text-label font-bold uppercase tracking-wider text-ink-400" x-text="item.type === 'group' ? 'Group' : 'Subject'"></span>
                                        <span class="min-w-0 flex-1 truncate text-nav font-semibold text-ink">
                                            <template x-for="piece in pieces(item.label)"><span x-text="piece.text" x-bind:class="piece.bold ? 'font-extrabold' : ''"></span></template>
                                        </span>
                                        <span class="shrink-0 rounded-pill px-2 py-0.5 text-label font-extrabold text-ink" data-subject-chip
                                              x-bind:style="'background-color: ' + item.accent" x-bind:data-palette="item.palette" x-text="item.subject_code" x-bind:title="item.subject_name"></span>
                                    </span>
                                </template>
                            </div>
                        </template>

                        <p x-show="done && items.length === 0" class="px-3 py-3 text-meta text-ink-400" data-no-match>
                            No students match '<span x-text="query.trim()"></span>'
                        </p>
                    </div>
                </div>

                <x-button type="submit">Search</x-button>

                @if ($search !== '' || $filterGroup || $filterSubject)
                    <x-button variant="secondary" :href="route('lecturer.students')">Clear</x-button>
                @endif
            </form>

            @if ($filterGroup || $filterSubject)
                <p class="text-meta font-semibold text-ink-600" data-active-filter>
                    Showing {{ $filterGroup ? 'group '.$filterGroup->name : 'subject '.$filterSubject->code.' - '.$filterSubject->name }}.
                </p>
            @endif

            <x-data-table :headings="['Student', 'Subject', ['CGPA', 'right'], ['Contribution', 'right'], 'Last active']"
                          
                          :empty="($search !== '' || $filterGroup || $filterSubject)
                              ? 'No students match that search.'
                              : 'No students have been assigned to your groups yet.'">
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <x-table.person :name="$row['user']->name">
                                <span class="truncate">{{ $row['user']->matric_no ?? $row['user']->email }}</span>
                                <span aria-hidden="true">·</span>
                                <a href="{{ route('lecturer.progress.show', $row['group']) }}"
                                   class="interactive rounded-md font-semibold text-periwinkle-link hover:underline">{{ $row['group']->name }}</a>
                            </x-table.person>
                        </td>

                        <td>
                            <span class="flex flex-wrap items-center gap-1.5">
                                <x-subject-chip :subject="$row['group']->subject" />
                                <x-section-chip :section="$row['group']->section" :code="$row['group']->subject->code" />
                            </span>
                        </td>

                        <td class="num">
                            @if ($row['user']->cgpa !== null)
                                <span class="text-nav font-bold">{{ number_format((float) $row['user']->cgpa, 2) }}</span>
                            @else
                                {{-- A dash, never a zero: an unrecorded CGPA must not read as 0.00. --}}
                                <span class="text-meta text-ink-300" title="No CGPA has been entered for this student" aria-label="No CGPA recorded">—</span>
                            @endif
                        </td>

                        <td>
                            <x-table.score :score="$row['score']" :fill="$row['share']" :flagged="$row['flagged']" class="ml-auto">
                                <span>{{ round($row['share']) }}%</span>
                                @if ($row['flagged'])
                                    <x-table.dot-pill tone="danger"
                                             title="Below {{ round($row['threshold'], 1) }} points, the low-participation threshold for this group">
                                        Low
                                    </x-table.dot-pill>
                                @endif
                            </x-table.score>
                        </td>

                        <td class="whitespace-nowrap text-meta text-ink-400">
                            @if ($row['last_at'])
                                <span title="{{ \Illuminate\Support\Carbon::parse($row['last_at'])->format('j F Y, g:ia') }}">
                                    {{ \Illuminate\Support\Carbon::parse($row['last_at'])->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-ink-300">Nothing recorded</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-data-table>

            <p class="text-label text-ink-400">
                Contribution is the member's score within that group, from the activity log. A student in more
                than one of your subjects appears once per group, because their standing is per group.
            </p>

            <p class="text-label text-ink-400">
                CGPA is entered by hand by the administrator. CollabSpace is not connected to any academic
                system, so treat it as a working figure that may be out of date, not as the official record.
            </p>
        @endif
    </div>

</x-layouts.app>
