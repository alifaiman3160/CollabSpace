{{-- The files of one block (an assessment's, or the whole subject's), with a delete each. Expects $subject and $files; $shared marks the course materials, whose rows say which assessments they are shown under. --}}
@if ($files->isEmpty())
    <p class="text-meta text-ink-300" data-files-empty>No files yet.</p>
@else
    <ul class="flex flex-col gap-2" data-files-list>
        @foreach ($files as $file)
            <x-subject-file-row :file="$file" manage :scope="($shared ?? false) && $subject->assessments->count() > 1 ? $file->audienceLabel() : null">
                <x-table.quiet-action icon="trash" aria-label="Delete {{ $file->title }}"
                    x-on:click="confirm = {
                        url: '{{ route('lecturer.subjects.files.destroy', [$subject, $file]) }}',
                        method: 'DELETE',
                        title: 'Delete this file?',
                        body: {{ Js::from('Students will no longer be able to open '.$file->title.'.') }},
                        action: 'Delete file',
                        tone: 'danger',
                    }">Delete</x-table.quiet-action>
            </x-subject-file-row>
        @endforeach
    </ul>
@endif
