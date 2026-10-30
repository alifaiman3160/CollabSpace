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
            <form method="GET" action="{{ route('lecturer.students') }}" class="flex flex-wrap items-center gap-3">
                <label class="flex h-11 min-w-[260px] flex-1 items-center gap-2.5 rounded-pill bg-surface-muted px-[18px]">
                    <x-icon name="search" size="17" class="text-ink-300" />
                    <span class="sr-only">Search students</span>
                    <input type="search" name="search" value="{{ $search }}"
                           placeholder="Search name, matric no., group or subject"
                           class="w-full min-w-0 border-none bg-transparent p-0 text-body text-ink outline-none placeholder:text-ink-300 focus:ring-0">
                </label>

                <x-button type="submit">Search</x-button>

                @if ($search !== '')
                    <x-button variant="secondary" :href="route('lecturer.students')">Clear</x-button>
                @endif
            </form>

            <x-data-table :headings="['Student', 'Subject', ['CGPA', 'right'], ['Contribution', 'right'], 'Last active']"
                          :empty="$search !== ''
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
                            <x-badge tone="info" :title="$row['group']->subject->name">
                                {{ $row['group']->subject->code }}
                            </x-badge>
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
