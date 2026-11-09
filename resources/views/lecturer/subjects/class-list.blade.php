<x-layouts.app :title="'Class list — '.$subject->code.' — CollabSpace'"
               heading="Class list"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('lecturer.groups', ['subject' => $subject->id])"
               :back-label="$subject->code.' groups'">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        {{-- Said plainly, so nobody goes looking for an edit button. --}}
        <div class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4" data-read-only-note>
            <x-icon name="eye" size="18" class="shrink-0 text-ink-300" />
            <p class="min-w-0 flex-1 text-meta text-ink-400">
                Read-only. The administrator enrols students in a subject and its section; groups are then formed
                within a section.
            </p>
        </div>

        @if ($total === 0)
            <x-empty-state icon="users" title="Nobody is enrolled yet"
                           body="Once the administrator enrols students in this subject, they are listed here by section." />
        @else
            <p class="-mt-1 text-label text-ink-400" data-class-summary>
                {{ $total }} {{ Str::plural('student', $total) }} enrolled · {{ $ungrouped }} without a group
            </p>

            @foreach ($sections as $section)
                @php($rows = $bySection->get($section->id, collect()))
                <section class="flex flex-col gap-3" aria-label="Section {{ $section->name }}" data-class-section="{{ $section->name }}">
                    <h2 class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                        Section {{ $section->name }}
                        <span class="text-meta font-semibold text-ink-400">({{ $rows->count() }} {{ Str::plural('student', $rows->count()) }})</span>
                    </h2>

                    <x-data-table :headings="['Student', 'Matric no.', 'Group']" empty="Nobody is enrolled in this section.">
                        @foreach ($rows as $enrolment)
                            <tr data-class-row>
                                <td>
                                    <x-table.person :name="$enrolment->user->name">
                                        <span class="truncate">{{ $enrolment->user->email }}</span>
                                    </x-table.person>
                                </td>
                                <td class="text-meta font-semibold text-ink-400">{{ $enrolment->user->matric_no ?? '—' }}</td>
                                <td>
                                    @if ($groupOf->has($enrolment->user_id))
                                        <span class="inline-flex items-center gap-1.5 text-meta font-semibold">{{ $groupOf[$enrolment->user_id] }}@if ($leaders->has($enrolment->user_id))<x-leader-badge />@endif</span>
                                    @else
                                        <x-table.dot-pill tone="warning" data-no-group>No group yet</x-table.dot-pill>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-data-table>
                </section>
            @endforeach
        @endif
    </div>

</x-layouts.app>
