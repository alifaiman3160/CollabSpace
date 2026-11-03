{{--
    The subject's assessments (Assignment 1, Assignment 2, Project ...): one block each, holding everything that belongs
    to it: its dates, its brief and its files. Then one block for the files of the whole subject. Expects $subject with
    its assessments and files loaded. Every form here is its own sibling form (edit, upload, add): none is nested.
    Errors of the assessment forms are in the "assessment" bag, apart from the subject's own form.
--}}
@php
    $bag = $errors->getBag('assessment');
    $active = $subject->activeAssessment();
    $count = $subject->assessments->count();
    $editingId = (int) old('editing', 0);
    $messages = collect($bag->all());
    $uploadTo = old('upload_to');
    $uploadErrors = $errors->hasAny(['file', 'title', 'kind', 'assessment_id']);
    $knownKeys = $subject->assessments->map(fn ($a) => 'a'.$a->id)->push('subject')->all();
    $sharedFiles = $subject->files->whereNull('assessment_id')->values();
    $sharedOpen = $sharedFiles->isNotEmpty() || $uploadTo === 'subject';
@endphp

<section id="assessments" class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle"
         x-data="{ editing: {{ $editingId ?: 'null' }}, adding: {{ old('editing') === '0' ? 'true' : 'false' }}, confirm: null }"
         data-assessments aria-label="Assessments">

    <div class="flex flex-col gap-1">
        <h2 class="text-h3 font-bold tracking-title">Assessments</h2>
        <p class="text-meta leading-[1.5] text-ink-400" data-assessment-help>
            Dates cannot overlap. With more than one assessment, activity counts only inside an assessment's dates, and none of it counts between them.
        </p>
        <p class="text-meta leading-[1.5] text-ink-400" data-files-help>
            Files: PDF, Word, PowerPoint or Excel, up to 10 MB each, {{ \App\Support\SubjectFiles::MAX_PER_SUBJECT }} per subject.
        </p>
    </div>

    @if ($messages->isNotEmpty())
        <div class="flex flex-col gap-1 rounded-card bg-danger-bg px-4 py-3" role="alert" data-assessment-errors>
            @foreach ($messages as $message)
                <p class="text-meta font-semibold text-danger-text">{{ $message }}</p>
            @endforeach
        </div>
    @endif

    {{-- An upload that failed for a block that is not on the page any more (a hand-made request). --}}
    @if ($uploadErrors && ! in_array($uploadTo, $knownKeys, true))
        <div class="flex flex-col gap-1 rounded-card bg-danger-bg px-4 py-3" role="alert" data-upload-errors>
            @foreach (array_merge($errors->get('file'), $errors->get('title'), $errors->get('kind'), $errors->get('assessment_id')) as $message)
                <p class="text-meta font-semibold text-danger-text">{{ $message }}</p>
            @endforeach
        </div>
    @endif

    @if ($subject->assessments->isEmpty())
        <p class="text-meta text-ink-300" data-assessments-empty>No assessments yet.</p>
    @else
        <ul class="grid items-stretch gap-3 min-[1280px]:grid-cols-2" data-assessment-list>
            @foreach ($subject->assessments as $assessment)
                @php
                    $block = $assessment->deleteBlock();
                    $files = $subject->files->where('assessment_id', $assessment->id)->values();
                    $fileCount = $files->count();
                    $brief = trim((string) $assessment->brief);
                    $long = mb_strlen($brief) > 220 || substr_count($brief, "\n") >= 3;
                    $mine = $uploadTo === 'a'.$assessment->id;
                @endphp
                <li id="assessment-{{ $assessment->id }}" class="flex min-w-0 scroll-mt-6 flex-col gap-3 rounded-card border border-surface-line bg-surface px-4 py-3.5 min-[1280px]:last:odd:col-span-full"
                    x-bind:class="(editing === {{ $assessment->id }} || uploading) ? 'min-[1280px]:col-span-full' : ''"
                    x-data="{ more: false, uploading: {{ $mine ? 'true' : 'false' }} }" data-assessment="{{ $assessment->id }}">

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <span class="inline-flex shrink-0 items-center rounded-pill bg-surface-muted px-2 py-px text-label font-bold text-ink-600" data-assessment-type>{{ $assessment->typeLabel() }}</span>
                        <span class="min-w-[8rem] flex-1 truncate text-nav font-bold" title="{{ $assessment->title }}">{{ $assessment->title }}</span>
                        @if ($active && $active->is($assessment) && $count > 1)
                            <x-table.dot-pill tone="success" data-assessment-active>Active</x-table.dot-pill>
                        @endif
                        <x-table.quiet-action tone="neutral" icon="edit" x-on:click="editing = editing === {{ $assessment->id }} ? null : {{ $assessment->id }}; adding = false">Edit</x-table.quiet-action>
                        <x-table.quiet-action icon="trash" aria-label="Delete {{ $assessment->title }}"
                            :data-tooltip="$block ? 'Cannot be deleted: '.$block : null"
                            x-on:click="confirm = {
                                url: '{{ route('lecturer.subjects.assessments.destroy', [$subject, $assessment]) }}',
                                method: 'DELETE',
                                title: 'Delete this assessment?',
                                body: {{ Js::from('Its rubrics go with it. '.($fileCount > 0 ? $fileCount.' '.str('file')->plural($fileCount).' for it will stay, under Course materials. ' : '').($block ? 'It cannot be deleted while '.$block.'.' : 'Nothing has been marked for it.')) }},
                                action: 'Delete assessment',
                                tone: 'danger',
                            }">Delete</x-table.quiet-action>
                    </div>

                    <p class="text-meta text-ink-400" data-assessment-dates>
                        {{ $assessment->starts_on?->format('j M Y') ?? 'No start date' }} to {{ $assessment->due_on?->format('j M Y') ?? 'no due date' }}
                        @if ($assessment->weight !== null) · {{ rtrim(rtrim((string) $assessment->weight, '0'), '.') }}% of the course @endif
                    </p>

                    {{-- The brief and the files, always visible. --}}
                    <div class="flex flex-col gap-1.5" data-assessment-brief>
                        <h3 class="text-label font-bold uppercase tracking-eyebrow text-ink-400">Brief</h3>
                        @if ($brief === '')
                            <p class="text-meta text-ink-300" data-brief-empty>No brief yet</p>
                        @elseif (! $long)
                            <p class="whitespace-pre-line text-body leading-[1.6] text-ink-600">{{ $brief }}</p>
                        @else
                            <p class="line-clamp-3 whitespace-pre-line text-body leading-[1.6] text-ink-600" x-show="! more">{{ $brief }}</p>
                            <x-collapse open="more" id="brief-full-{{ $assessment->id }}">
                                <p class="whitespace-pre-line text-body leading-[1.6] text-ink-600">{{ $brief }}</p>
                            </x-collapse>
                            <div>
                                <button type="button" class="interactive btn-motion rounded-pill px-2 py-1 text-meta font-bold text-periwinkle-link"
                                        x-on:click="more = ! more" x-bind:aria-expanded="more ? 'true' : 'false'" aria-controls="brief-full-{{ $assessment->id }}"
                                        x-text="more ? 'Show less' : 'Show more'">Show more</button>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-col gap-1.5" data-assessment-files>
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-label font-bold uppercase tracking-eyebrow text-ink-400">Files</h3>
                            <x-table.quiet-action tone="neutral" icon="plus" x-on:click="uploading = ! uploading" x-bind:aria-expanded="uploading ? 'true' : 'false'">Upload file</x-table.quiet-action>
                        </div>
                        @include('lecturer.subjects._file-list', ['subject' => $subject, 'files' => $files])
                    </div>

                    @include('lecturer.subjects._upload', ['subject' => $subject, 'key' => 'a'.$assessment->id, 'assessmentId' => $assessment->id, 'mine' => $mine])

                    <form method="POST" action="{{ route('lecturer.subjects.assessments.update', [$subject, $assessment]) }}" x-show="editing === {{ $assessment->id }}" x-cloak
                          class="flex flex-col gap-3 border-t border-surface-line pt-3" data-assessment-edit>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="editing" value="{{ $assessment->id }}">

                        @include('lecturer.subjects._assessment-fields', ['values' => $assessment, 'old' => $editingId === $assessment->id, 'idPrefix' => 'a'.$assessment->id])

                        @if ($assessment->hasApprovals())
                            <p class="rounded-card bg-warning-bg px-3.5 py-2.5 text-meta font-semibold text-warning-text" data-assessment-warning>
                                Marks are already approved for this assessment. Changing its dates does not change them: frozen marks keep their snapshot.
                            </p>
                        @endif

                        <div class="flex flex-wrap items-center gap-2.5">
                            <x-button type="submit" class="!px-4 !py-2 !text-meta">Save assessment</x-button>
                            <x-button type="button" variant="secondary" class="!px-4 !py-2 !text-meta" x-on:click="editing = null">Cancel</x-button>
                        </div>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Files for every assessment of the subject. Collapsed to a link while there are none. --}}
    <div id="assessment-shared" class="flex min-w-0 scroll-mt-6 flex-col gap-3 rounded-card border border-surface-line bg-surface px-4 py-3.5"
         x-data="{ uploading: {{ $uploadTo === 'subject' ? 'true' : 'false' }}, shown: {{ $sharedOpen ? 'true' : 'false' }} }" data-whole-subject>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <span class="min-w-0 flex-1 truncate text-nav font-bold">Course materials</span>
            @if ($sharedFiles->isNotEmpty())
                <x-table.quiet-action tone="neutral" icon="plus" x-on:click="uploading = ! uploading" x-bind:aria-expanded="uploading ? 'true' : 'false'">Upload file</x-table.quiet-action>
            @endif
        </div>

        @if ($sharedFiles->isEmpty())
            <div x-show="! shown">
                <button type="button" class="interactive btn-motion rounded-pill px-2 py-1 text-meta font-bold text-periwinkle-link"
                        x-on:click="shown = true; uploading = true" data-add-shared>+ Add a shared file</button>
            </div>
            <div class="flex flex-col gap-3" x-show="shown" x-cloak>
                <p class="text-meta text-ink-400">Shown under every assessment, or only the ones you tick when uploading.</p>
                @include('lecturer.subjects._file-list', ['subject' => $subject, 'files' => $sharedFiles, 'shared' => true])
            </div>
        @else
            <div class="flex flex-col gap-1.5" data-assessment-files>
                <p class="text-meta text-ink-400">Shown under every assessment, or only the ones you tick when uploading.</p>
                @include('lecturer.subjects._file-list', ['subject' => $subject, 'files' => $sharedFiles, 'shared' => true])
            </div>
        @endif

        @include('lecturer.subjects._upload', ['subject' => $subject, 'key' => 'subject', 'assessmentId' => null, 'mine' => $uploadTo === 'subject'])
    </div>

    @if ($count < \App\Models\Assessment::MAX_PER_SUBJECT)
        <div>
            <x-button type="button" variant="secondary" icon="plus" class="!px-4 !py-2 !text-meta" x-on:click="adding = ! adding; editing = null" x-show="! adding">Add assessment</x-button>
        </div>

        <form id="assessment-new" method="POST" action="{{ route('lecturer.subjects.assessments.store', $subject) }}" x-show="adding" x-cloak
              class="flex flex-col gap-3 border-t border-surface-line pt-4" data-assessment-add>
            @csrf
            <input type="hidden" name="editing" value="0">

            @include('lecturer.subjects._assessment-fields', ['values' => null, 'old' => old('editing') === '0', 'idPrefix' => 'new'])

            <div class="flex flex-wrap items-center gap-2.5">
                <x-button type="submit" class="!px-4 !py-2 !text-meta">Add assessment</x-button>
                <x-button type="button" variant="secondary" class="!px-4 !py-2 !text-meta" x-on:click="adding = false">Cancel</x-button>
            </div>
        </form>
    @else
        <p class="text-meta text-ink-400" data-assessments-full>A subject can have at most {{ \App\Models\Assessment::MAX_PER_SUBJECT }} assessments.</p>
    @endif

    <x-confirm-dialog />
</section>
