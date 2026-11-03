<x-layouts.app title="Marking — CollabSpace"
               heading="Marking"
               subtitle="Each subject's rubric, and the groups to mark with it.">

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <x-subject-section-filter :filter="$filter" route="lecturer.marking" />

        @if ($subjects->isEmpty())
            <x-empty-state
                class="!border-solid !border-surface-line"
                icon="book"
                title="No subjects assigned yet"
                body="Marking uses a rubric for a subject, and the administrator assigns subjects to lecturers." />
        @else
            {{-- Rubrics --}}
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Rubrics</h2>

                <div class="grid grid-cols-[repeat(auto-fill,minmax(20rem,1fr))] gap-[18px]" data-rubric-grid>
                    @foreach ($subjects as $i => $subject)
                        @php
                            $rubric = $rubrics->get($subject->id);
                            $assessment = $chosen->get($subject->id);
                        @endphp

                        <x-subject-card :subject="$subject" :i="$i">
                            @if ($rubric && $locked->has($rubric->id))
                                <x-slot:badge><x-table.dot-pill tone="warning">Locked</x-table.dot-pill></x-slot:badge>
                            @endif

                            @if ($subject->assessments->count() > 1 && $assessment)
                                <x-assessment-chip :assessment="$assessment" class="mt-1 w-fit" />
                            @endif

                            @if ($rubric)
                                <a href="{{ route('lecturer.rubrics.show', [$subject, $rubric]) }}" class="interactive mt-1 flex w-fit flex-col rounded-md">
                                    <span class="text-nav font-bold">{{ $rubric->name }}</span>
                                    <span class="text-label font-medium text-ink-600">Version {{ $rubric->version }}</span>
                                </a>
                            @else
                                <span class="mt-1 text-meta font-medium text-ink-600">No rubric yet.</span>
                            @endif

                            <x-slot:footer>
                                @if ($rubric)
                                    <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                              :href="route('lecturer.rubrics', [$subject] + ($subject->assessments->count() > 1 ? ['assessment' => $assessment?->id] : []))">Upload or manage</x-button>
                                @else
                                    <x-button class="!px-4 !py-2 !text-meta"
                                              :href="route('lecturer.rubrics', [$subject] + ($subject->assessments->count() > 1 ? ['assessment' => $assessment?->id] : []))">Upload a rubric</x-button>
                                @endif
                            </x-slot:footer>
                        </x-subject-card>
                    @endforeach
                </div>
            </section>

            {{-- Groups --}}
            <section class="flex flex-col gap-3">
                <h2 class="text-h3 font-extrabold tracking-title">Groups to mark</h2>

                @if ($subjects->flatMap->groups->isEmpty())
                    <x-empty-state class="!border-solid !border-surface-line" icon="folder" title="No groups yet"
                                   body="Create groups for a subject, then mark them here." />
                @else
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(20rem,1fr))] gap-[18px]" data-group-grid>
                        @php $n = 0; @endphp
                        @foreach ($subjects as $subject)
                            @php
                            $rubric = $rubrics->get($subject->id);
                            $assessment = $chosen->get($subject->id);
                        @endphp

                            @foreach ($subject->groups as $group)
                                @php
                                    $students = (int) $group->members_count;
                                    $done = $rubric ? (int) $approved->get($rubric->id.'-'.$group->id, 0) : 0;
                                    $status = ! $rubric ? 'Needs a rubric'
                                        : ($students > 0 && $done === $students ? 'All approved' : ($done > 0 ? 'In progress' : 'Not started'));
                                @endphp

                                <x-group-card :group="$group->setRelation('subject', $subject)" :i="$n++" variant="light">
                                    @if ($submissionMap !== null && $assessment)
                                        <x-slot:chip><x-submission-chip :submission="$submissionMap->get($group->id.'-'.$assessment->id)" /></x-slot:chip>
                                    @endif
                                    <div class="mt-auto flex flex-col gap-2 rounded-card bg-white/60 p-3" data-approval>
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-meta font-semibold text-ink-600">{{ $students }} {{ Str::plural('student', $students) }}</span>
                                            @if ($status === 'All approved')
                                                <x-table.dot-pill tone="success">{{ $status }}</x-table.dot-pill>
                                            @elseif ($status === 'In progress')
                                                <x-table.dot-pill tone="warning">{{ $status }}</x-table.dot-pill>
                                            @else
                                                <x-table.dot-pill tone="neutral">{{ $status }}</x-table.dot-pill>
                                            @endif
                                        </div>

                                        @if ($rubric)
                                            <x-progress-bar :done="$done" :total="$students" height="6px" />
                                            <span class="text-label font-medium text-ink-400">{{ $done }} of {{ $students }} approved</span>
                                        @endif
                                    </div>

                                    <x-slot:footer>
                                        @if ($rubric)
                                            <x-button class="!px-4 !py-2 !text-meta"
                                                      :href="route('lecturer.marking.group', [$rubric, $group])">Mark</x-button>
                                        @else
                                            <x-button variant="secondary" class="!px-4 !py-2 !text-meta"
                                                      :href="route('lecturer.rubrics', [$subject] + ($subject->assessments->count() > 1 ? ['assessment' => $assessment?->id] : []))">Upload a rubric</x-button>
                                        @endif
                                    </x-slot:footer>
                                </x-group-card>
                            @endforeach
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
    </div>

</x-layouts.app>