{{--
    The inline upload form of one block: an assessment's own, or "Whole subject". Expects $subject, $key ("a12" or
    "subject"), $assessmentId (the assessment it uploads to, null for the whole subject) and $mine (this block's own
    errors are shown here). A separate form, never nested in another; the file input needs multipart.
--}}
<form method="POST" action="{{ route('lecturer.subjects.files.store', $subject) }}" enctype="multipart/form-data"
      x-show="uploading" @unless ($mine) x-cloak @endunless
      class="flex flex-col gap-3 border-t border-surface-line pt-3" data-file-form
      x-data="{ kind: '{{ $mine ? old('kind', 'question') : 'question' }}' }">
    @csrf
    <input type="hidden" name="assessment_id" value="{{ $assessmentId }}">
    <input type="hidden" name="upload_to" value="{{ $key }}">

    <div class="grid items-start gap-3 sm:grid-cols-2">
        <div class="flex flex-col gap-2 sm:col-span-2">
            <x-input-label for="{{ $key }}_file" value="File" />
            <input id="{{ $key }}_file" name="file" type="file" required accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx"
                   class="block w-full rounded-[16px] border border-surface-line bg-surface p-3 text-meta text-ink-600
                          file:mr-4 file:rounded-pill file:border-0 file:bg-surface-muted file:px-4 file:py-2
                          file:text-meta file:font-bold file:text-ink hover:file:bg-surface-hover">
        </div>

        <div class="flex flex-col gap-2">
            <x-input-label for="{{ $key }}_file_title" value="Title" />
            <x-text-input id="{{ $key }}_file_title" name="title" type="text" required maxlength="120"
                          :value="$mine ? old('title') : ''" placeholder="For example: Question" />
            @if ($mine)<x-input-error :messages="$errors->get('title')" />@endif
        </div>

        <div class="flex flex-col gap-2">
            <x-input-label for="{{ $key }}_file_kind" value="Kind" />
            <select id="{{ $key }}_file_kind" name="kind" x-model="kind"
                    class="h-[52px] w-full rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                <option value="question">Question</option>
                <option value="marking_guide">Marking guide (file)</option>
                <option value="other">Other</option>
            </select>
            @if ($mine)<x-input-error :messages="$errors->get('kind')" />@endif
        </div>

        <p class="text-meta text-ink-400 sm:col-span-2" x-show="kind === 'marking_guide'" x-cloak data-marking-guide-help>
            For students to read. Marks are calculated from the rubric set up in the system, not from this file.
        </p>
    </div>

    {{-- Everything the server refuses about the file itself (type, size, how many) lands here. --}}
    @if ($mine)
        <div data-file-errors>
            <x-input-error :messages="array_merge($errors->get('file'), $errors->get('assessment_id'))" />
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2.5">
        <x-button type="submit" icon="plus" class="!px-4 !py-2 !text-meta">Upload file</x-button>
        <x-button type="button" variant="secondary" class="!px-4 !py-2 !text-meta" x-on:click="uploading = false">Cancel</x-button>
    </div>
</form>
