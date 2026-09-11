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

            <x-data-table :headings="['Student', 'Subject', 'Group', 'Contribution', 'Last active']"
                          :empty="$search !== ''
                              ? 'No students match that search.'
                              : 'No students have been assigned to your groups yet.'">
                @foreach ($rows as $row)
                    <tr class="hover:bg-surface-soft">
                        <td>
                            <div class="flex items-center gap-3">
                                <x-avatar :name="$row['user']->name" size="sm" />
                                <span class="flex min-w-0 flex-col">
                                    <span class="text-nav font-bold">{{ $row['user']->name }}</span>
                                    <span class="truncate text-meta text-ink-400">
                                        {{ $row['user']->matric_no ?? $row['user']->email }}
                                    </span>
                                </span>
                            </div>
                        </td>

                        <td>
                            <x-badge tone="info" :title="$row['group']->subject->name">
                                {{ $row['group']->subject->code }}
                            </x-badge>
                        </td>

                        <td>
                            <a href="{{ route('lecturer.progress.show', $row['group']) }}"
                               class="text-meta font-semibold text-periwinkle-link hover:underline">
                                {{ $row['group']->name }}
                            </a>
                        </td>

                        <td>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-nav font-bold {{ $row['flagged'] ? 'text-danger-text' : '' }}">
                                    {{ $row['score'] }} pts
                                </span>
                                <span class="text-meta text-ink-400">{{ round($row['share']) }}%</span>
                                @if ($row['flagged'])
                                    <x-badge tone="danger"
                                             title="Below {{ round($row['threshold'], 1) }} points, the low-participation threshold for this group">
                                        Low
                                    </x-badge>
                                @endif
                            </div>
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
        @endif
    </div>

</x-layouts.app>
