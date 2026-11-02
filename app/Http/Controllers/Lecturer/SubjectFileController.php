<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Subject;
use App\Models\SubjectFile;
use App\Support\SubjectFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The lecturer's project files for a subject they teach: upload and delete. Reading is the shared download route.
 * Only the owning lecturer gets here (anyone else's subject is a 404); the checks are all on the server.
 */
class SubjectFileController extends LecturerController
{
    public function store(Request $request, Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);
        $back = redirect()->to(route('lecturer.subjects.edit', $subject).'#'.$this->anchor($request->input('assessment_id')));

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.SubjectFiles::MAX_KB],
            'title' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(SubjectFile::KINDS))],
            // The assessment it is for; empty means the whole subject. Only this subject's own assessments are accepted.
            'assessment_id' => ['nullable', 'integer', Rule::exists('assessments', 'id')->where('subject_id', $subject->getKey())],
        ], [
            'file.required' => 'Choose a file to upload.',
            'file.uploaded' => 'The file did not arrive. It may be bigger than this server accepts (the limit is set by upload_max_filesize and post_max_size in php.ini).',
            'file.max' => 'That file is over 10 MB.',
            'title.required' => 'Give the file a title.',
            'title.max' => 'Keep the title to 120 characters.',
            'kind.required' => 'Say what kind of file it is.',
            'kind.in' => 'Choose one of the kinds in the list.',
            'assessment_id.exists' => 'Choose one of this subject\'s assessments, or the whole subject.',
        ]);

        $file = $request->file('file');

        if ($subject->files()->count() >= SubjectFiles::MAX_PER_SUBJECT) {
            return $back->withErrors(['file' => 'This subject already has '.SubjectFiles::MAX_PER_SUBJECT.' files. Delete one before adding another.'])->withInput();
        }

        $mime = SubjectFiles::verify($file);

        if ($mime === null) {
            $allowed = strtoupper(implode(', ', SubjectFiles::allowedExtensions()));

            return $back->withErrors([
                'file' => "That file was not accepted. Upload a real PDF, Word, PowerPoint or Excel file ({$allowed}); its content must match its extension.",
            ])->withInput();
        }

        SubjectFiles::store($subject, $file, $mime, trim($validated['title']), $validated['kind'], $request->user(), filled($validated['assessment_id'] ?? null) ? (int) $validated['assessment_id'] : null);

        return $back->with('status', 'File uploaded.');
    }

    public function destroy(Subject $subject, SubjectFile $file): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        abort_unless($file->subject_id === $subject->getKey(), 404);

        SubjectFiles::delete($file);

        return redirect()->to(route('lecturer.subjects.edit', $subject).'#'.$this->anchor($file->assessment_id))->with('status', 'File deleted.');
    }

    /** The block of the edit page the file belongs to: its assessment, or the whole-subject block. */
    protected function anchor($assessmentId): string
    {
        return filled($assessmentId) ? 'assessment-'.(int) $assessmentId : 'assessment-shared';
    }
}
