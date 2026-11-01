{{-- Back as a back link above the heading, not as a pill on the far right.
     The layout has supported this since it was written, and a secondary button
     off in the corner labelled "All SECJ3104 groups" read as a stray filter
     rather than the way out of this screen. Returns to the list filtered to
     this subject, which is the shelf this group was taken from. --}}
<x-layouts.app title="{{ $group->name }} — CollabSpace"
               :heading="$group->name"
               :subtitle="$group->subject->code.' — '.$group->subject->name.($group->section ? ' · Section '.$group->section->name : '')"
               :back="route('lecturer.groups', ['subject' => $group->subject_id])"
               :back-label="$group->subject->code.' groups'">

    @php
        $memberCount = $group->members->count();
        $isFull = $memberCount >= $group->group_size;
    @endphp

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        @include('lecturer.groups._formation', ['group' => $group])

        {{-- Overview --}}
        <section class="flex flex-wrap gap-3.5">
            <x-stat-card size="sm" tone="periwinkle" icon="users" icon-tone="neutral"
                         label="Members" :value="$memberCount.' / '.$group->group_size"
                         :meta="$isFull ? 'Group is full' : ($group->group_size - $memberCount).' '.Str::plural('place', $group->group_size - $memberCount).' left'" />

            <x-stat-card size="sm" icon="check" icon-tone="success"
                         label="Tasks done" :value="$group->tasks_done"
                         :meta="'of '.$group->tasks_total" />

            <x-stat-card size="sm" icon="alert" icon-tone="danger"
                         label="Overdue" :value="$group->tasks_overdue"
                         :meta="$group->tasks_overdue ? 'Past deadline' : 'On schedule'" />

            <div class="flex min-w-[220px] flex-1 flex-col justify-center rounded-panel border border-surface-line bg-surface shadow-subtle p-[18px]">
                <x-task-progress :done="$group->tasks_done"
                                 :total="$group->tasks_total"
                                 :overdue="$group->tasks_overdue" />
            </div>
        </section>

        {{-- Assign a student --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-h3 font-extrabold tracking-title">Assign a student</h2>

            {{-- Outside the form: a rejected assignment often leaves the group
                 full or with nobody left to assign, and the form is then not
                 rendered at all. --}}
            @if ($errors->has('user_id'))
                <div class="flex items-center gap-2.5 rounded-card bg-danger-bg px-4 py-3 text-body font-semibold text-danger-text">
                    <x-icon name="alert" size="17" class="shrink-0" />
                    <span>{{ $errors->first('user_id') }}</span>
                </div>
            @endif

            @if ($isFull)
                <div class="flex items-center gap-2.5 rounded-card border border-surface-line bg-surface-soft px-4 py-3.5 text-body text-ink-400">
                    <x-icon name="alert" size="17" class="shrink-0 text-ink-300" />
                    <span>
                        This group is at its size limit of {{ $group->group_size }}. Remove a member,
                        or raise the limit, before assigning anyone else.
                    </span>
                </div>
            @elseif ($assignable->isEmpty())
                <div class="flex items-center gap-2.5 rounded-card border border-surface-line bg-surface-soft px-4 py-3.5 text-body text-ink-400">
                    <x-icon name="users" size="17" class="shrink-0 text-ink-300" />
                    <span>
                        Every student enrolled in {{ $group->subject->code }}{{ $group->section ? ' section '.$group->section->name : '' }} already belongs to a group.
                    </span>
                </div>
            @else
                <form method="POST" action="{{ route('lecturer.groups.members.store', $group) }}"
                      class="flex max-w-2xl flex-wrap items-start gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle">
                    @csrf

                    <div class="flex min-w-[260px] flex-1 flex-col gap-2">
                        <select name="user_id" required
                                class="h-[52px] w-full rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                            <option value="">Choose a student…</option>
                            @foreach ($assignable as $student)
                                <option value="{{ $student->id }}">
                                    {{ $student->name }}@if($student->matric_no) — {{ $student->matric_no }}@endif
                                    @if ($sec = $student->enrolments->firstWhere('subject_id', $group->subject_id)?->section) · {{ $sec->name }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <x-button type="submit" class="!h-[52px]">Assign</x-button>
                </form>

                <p class="text-meta text-ink-400">
                    Only students enrolled in {{ $group->subject->code }}{{ $group->section ? ', section '.$group->section->name.',' : '' }}
                    with no group yet are listed — a student belongs to one group per subject, and a group holds one section.
                </p>
            @endif
        </section>

        {{-- Members --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-h3 font-extrabold tracking-title">Members</h2>

            <x-data-table :headings="['Student', 'Matric no.', 'Joined', '']"
                          empty="No students have been assigned to this group yet.">
                @foreach ($group->members as $member)
                    @php
                        $removeBody = "{$member->name} will be removed from {$group->name}. Their tasks, "
                            ."documents and messages in this group are kept, but they lose access to it "
                            ."and can then be assigned elsewhere in {$group->subject->code}.";
                    @endphp
                    <tr>
                        <td>
                            <x-table.person :name="$member->name">
                                <span class="truncate">{{ $member->email }}</span>
                            </x-table.person>
                        </td>

                        <td class="text-meta font-semibold text-ink-400">{{ $member->matric_no ?? '—' }}</td>

                        <td class="whitespace-nowrap text-meta text-ink-400">
                            {{ $member->membership->joined_at?->format('j M Y') ?? '—' }}
                        </td>

                        <td>
                            <div class="flex justify-end">
                                <x-table.quiet-action icon="trash" aria-label="Remove {{ $member->name }}"
                                    x-on:click="confirm = {
                                        url: '{{ route('lecturer.groups.members.destroy', [$group, $member]) }}',
                                        method: 'DELETE',
                                        title: 'Remove this student?',
                                        body: {{ Js::from($removeBody) }},
                                        action: 'Remove',
                                        tone: 'danger',
                                    }">Remove</x-table.quiet-action>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        </section>

        <x-confirm-dialog />
    </div>

</x-layouts.app>
