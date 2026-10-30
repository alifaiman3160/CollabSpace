<x-layouts.app title="Marking — CollabSpace"
               heading="Marking"
               subtitle="Each subject's rubric, and the groups to mark with it.">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        @if ($subjects->isEmpty())
            <x-empty-state
                icon="book"
                title="No subjects yet"
                body="Marking uses a rubric for a subject. Create a subject, then upload its rubric."
                cta="Create a subject"
                :href="route('lecturer.subjects.create')" />
        @else
            {{-- Rubrics --}}
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Rubrics</h2>

                <x-data-table :headings="['Subject', 'Current rubric', '']" min-width="620px">
                    @foreach ($subjects as $subject)
                        @php $rubric = $rubrics->get($subject->id); @endphp
                        <tr>
                            <td>
                                <x-table.stack>
                                    <x-slot:primary><x-badge tone="info">{{ $subject->code }}</x-badge></x-slot:primary>
                                    <span class="truncate">{{ $subject->name }}</span>
                                </x-table.stack>
                            </td>

                            <td>
                                @if ($rubric)
                                    <x-table.stack :href="route('lecturer.rubrics.show', [$subject, $rubric])">
                                        <x-slot:primary>{{ $rubric->name }}</x-slot:primary>
                                        Version {{ $rubric->version }}
                                    </x-table.stack>
                                @else
                                    <span class="text-meta text-ink-300">—</span>
                                @endif
                            </td>

                            <td>
                                <div class="flex justify-end">
                                    @if ($rubric)
                                        <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                                  :href="route('lecturer.rubrics', $subject)">Upload or manage</x-button>
                                    @else
                                        <x-button class="!px-4 !py-2 !text-meta"
                                                  :href="route('lecturer.rubrics', $subject)">Upload a rubric</x-button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            </section>

            {{-- Groups --}}
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Groups to mark</h2>

                @if ($subjects->flatMap->groups->isEmpty())
                    <x-empty-state icon="folder" title="No groups yet"
                                   body="Create groups for a subject, then mark them here." />
                @else
                    <x-data-table :headings="['Group', ['Students', 'right'], 'Approved', '']" min-width="640px">
                        @foreach ($subjects as $subject)
                            @php $rubric = $rubrics->get($subject->id); @endphp

                            @foreach ($subject->groups as $group)
                                @php $done = $rubric ? (int) $approved->get($rubric->id.'-'.$group->id, 0) : 0; @endphp
                                <tr>
                                    <td>
                                        <x-table.stack>
                                            <x-slot:primary>{{ $group->name }}</x-slot:primary>
                                            {{ $subject->code }}
                                        </x-table.stack>
                                    </td>

                                    <td class="num text-meta font-semibold text-ink-400">{{ $group->members_count }}</td>

                                    <td>
                                        @if (! $rubric)
                                            <span class="text-meta text-ink-300">Needs a rubric</span>
                                        @elseif ($group->members_count > 0 && $done === $group->members_count)
                                            <x-table.dot-pill tone="success">All approved</x-table.dot-pill>
                                        @else
                                            <span class="text-meta font-semibold tabular-nums text-ink-600">{{ $done }} of {{ $group->members_count }}</span>
                                        @endif
                                    </td>

                                    <td>
                                        <div class="flex justify-end">
                                            @if ($rubric)
                                                <x-button class="!px-4 !py-2 !text-meta"
                                                          :href="route('lecturer.marking.group', [$rubric, $group])">Mark</x-button>
                                            @else
                                                <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                                          :href="route('lecturer.rubrics', $subject)">Upload a rubric</x-button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </x-data-table>
                @endif
            </section>
        @endif
    </div>

</x-layouts.app>
