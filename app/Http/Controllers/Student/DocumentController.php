<?php

namespace App\Http\Controllers\Student;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Support\ActivityRecorder;
use App\Support\DocumentPreview;
use App\Support\DocumentPrintBody;
use App\Support\DocumentSanitizer;
use App\Support\GroupFiles;
use App\Support\Notifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;
use Illuminate\View\View;

class DocumentController extends StudentController
{
    public function __construct(
        protected DocumentSanitizer $sanitizer,
        protected ActivityRecorder $recorder,
        protected Notifier $notifier,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    /** A document is reachable only through a group the student belongs to. */
    protected function ownedDocument(Document $document): Document
    {
        $document->loadMissing('group');

        abort_if($document->group === null, 404);

        $this->ownedGroup($document->group);

        return $document;
    }

    /** A version is reachable only through its own document. */
    protected function ownedVersion(Document $document, DocumentVersion $version): DocumentVersion
    {
        abort_unless($version->document_id === $document->getKey(), 404);

        return $version;
    }

    /*
    |--------------------------------------------------------------------------
    | List
    |--------------------------------------------------------------------------
    */

    public function index(): View|RedirectResponse
    {
        $groups = $this->myGroups();

        if ($groups->isEmpty()) {
            return view('student.documents.index', ['hasGroup' => false, 'groups' => $groups]);
        }

        return redirect()->route('student.documents.group', $groups->first());
    }

    public function group(Group $group): View
    {
        $group = $this->ownedGroup($group);
        $groups = $this->myGroups();

        return view('student.documents.index', [
            'hasGroup' => true,
            'groups' => $groups,
            'group' => $group->load('subject'),
            'secondary' => $this->groupSecondary($groups, 'documents'),
            'documents' => \App\Support\DocumentCards::forGroup($group),
            'submissions' => $group->submissions()->with('submitter:id,name', 'documents')->orderByDesc('submitted_at')->orderByDesc('id')->get()->groupBy('assessment_id'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    /** Title only — the editor is where the body gets written. */
    public function store(Request $request, Group $group): RedirectResponse
    {
        $group = $this->ownedGroup($group);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
        ]);

        $document = Document::create([
            'group_id' => $group->id,
            'created_by' => auth()->id(),
            'last_edited_by' => auth()->id(),
            'title' => trim($validated['title']),
            'content' => '',
        ]);

        // Version 1 is the empty document it starts as, so the history is
        // complete from the beginning.
        DocumentVersion::create([
            'document_id' => $document->id,
            'edited_by' => auth()->id(),
            'version_no' => 1,
            'content' => '',
        ]);

        $this->recorder->documentCreated($document, auth()->id());
        $this->notifier->documentCreated($document, auth()->user());

        return redirect()->route('student.documents.edit', $document)
            ->with('status', "“{$document->title}” created. Start writing.");
    }

    /*
    |--------------------------------------------------------------------------
    | Edit / save
    |--------------------------------------------------------------------------
    */

    public function edit(Document $document): View
    {
        $document = $this->ownedDocument($document);

        return view('student.documents.edit', [
            'group' => $document->group->load('subject'),
            'document' => $document,
            'versions' => $document->versions()->with('editor')->get(),
            'body' => $this->sanitizer->render($document->content),
        ]);
    }

    /**
     * Saves the body. A save that does not change the writing writes no version
     * row and no activity log — otherwise opening a document and pressing save
     * would inflate the author's contribution score.
     */
    public function update(Request $request, Document $document): RedirectResponse
    {
        $document = $this->ownedDocument($document);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:200000'],
        ]);

        $clean = $this->sanitizer->clean($validated['content'] ?? '');
        $title = trim($validated['title']);

        $contentChanged = ! $this->sanitizer->isUnchanged($document->content, $clean);
        $titleChanged = $title !== $document->title;

        if (! $contentChanged && ! $titleChanged) {
            return back()->with('status', 'No changes to save.');
        }

        if ($contentChanged) {
            $this->writeVersion($document, $clean);

            $document->forceFill([
                'title' => $title,
                'content' => $clean,
                'last_edited_by' => auth()->id(),
            ])->save();

            $this->recorder->documentEdited($document, auth()->id());
            $this->notifier->documentEdited($document, auth()->user());

            $message = 'Saved as version '.$document->versions()->max('version_no').'.';

            return $this->afterSave($request, $document, $message);
        }

        // Title-only edit: the body is unchanged, so it is not a new version
        // and does not score. The rename is still recorded on the document.
        $document->forceFill([
            'title' => $title,
            'last_edited_by' => auth()->id(),
        ])->save();

        return $this->afterSave($request, $document,
            'Title updated. The body is unchanged, so no new version was created.');
    }

    /*
    |--------------------------------------------------------------------------
    | PDF
    |--------------------------------------------------------------------------
    */

    /** More than this and a PDF is not attempted: it would take the server's memory or time. */
    protected const PDF_MAX_CHARACTERS = 500000;

    protected const PDF_MAX_PICTURES = 40;

    /**
     * The document's latest saved version as an A4 PDF, for any member of its group.
     *
     * Members only: someone outside the group gets 403, a document that does not exist gets 404. The
     * print layout (resources/views/documents/print.blade.php) puts the title first and carries no names;
     * the file name carries the group.
     */
    public function pdf(Request $request, Document $document, DocumentPrintBody $print): Response|JsonResponse|RedirectResponse
    {
        $document->loadMissing('group');

        abort_if($document->group === null, 404);
        abort_unless($this->student()->groupMemberships()->where('group_id', $document->group_id)->exists(), 403);

        $content = (string) $document->content;

        if (mb_strlen($content) > self::PDF_MAX_CHARACTERS || substr_count($content, 'data-image=') > self::PDF_MAX_PICTURES) {
            return $this->pdfFailed($request, 'This document is too large to turn into a PDF in one go. Try splitting it into two documents.');
        }

        try {
            @ini_set('memory_limit', '384M');
            @set_time_limit(60);

            $output = Pdf::setOption([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isHtml5ParserEnabled' => true,
            ])
                ->setPaper('a4')
                ->loadView('documents.print', ['title' => $document->title, 'body' => $print->render($content, $document)])
                ->output();
        } catch (Throwable $e) {
            report($e);

            return $this->pdfFailed($request, 'The PDF could not be prepared just now. Please try again in a moment.');
        }

        $name = $this->pdfFilename($document);

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, Str::ascii(str_replace('%', '', $name))),
            'Content-Length' => (string) strlen($output),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** "<title> - <group>.pdf" without anything a file system or a header would object to. */
    public function pdfFilename(Document $document): string
    {
        $name = $document->title.' - '.$document->group->name;
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', ' .');
        $name = trim(mb_substr($name, 0, 120), ' .');

        return ($name !== '' ? $name : 'Document').'.pdf';
    }

    protected function pdfFailed(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors(['status' => $message]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pictures
    |--------------------------------------------------------------------------
    */

    /**
     * Stores one picture for the editor and answers with the address to put in the body.
     *
     * The file goes on the private disk inside the group's folder, so it is removed with the group.
     * The type is checked on the file's own content as well as its extension.
     */
    public function storeImage(Request $request, Document $document): JsonResponse
    {
        $document = $this->ownedDocument($document);

        $validator = Validator::make($request->all(), [
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048'],
        ], [
            'image.required' => 'Choose a picture to insert.',
            'image.file' => 'That file could not be read. Try another picture.',
            'image.uploaded' => 'That picture is too large. Pictures can be up to 2 MB.',
            'image.mimes' => 'Only PNG, JPG or WebP pictures can be inserted.',
            'image.mimetypes' => 'Only PNG, JPG or WebP pictures can be inserted.',
            'image.max' => 'That picture is too large. Pictures can be up to 2 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('image')], 422);
        }

        $file = $request->file('image');
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $path = $file->storeAs(
            GroupFiles::directoryFor($document->group_id).'/documents/'.$document->id,
            Str::random(40).'.'.$extension,
            GroupFiles::DISK,
        );

        $image = $document->images()->create([
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
        ]);

        return response()->json(['id' => $image->id, 'url' => $image->url()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    /**
     * Removes a document from the group. It is soft deleted: the row, its versions, its pictures and the files on disk
     * all stay, and so does the credit it earned (the activity rows), so the writing remains available to the lecturer
     * as marking evidence. Who deleted it, and when, is recorded, and a document_deleted row is written to the
     * activity trail (worth no points).
     *
     * Any member of the group may do this, the rule the rest of the module runs on, since a group document belongs
     * to the group rather than to whoever typed it first. The UI says plainly that the lecturer can still see it.
     */
    public function destroy(Document $document): RedirectResponse
    {
        $document = $this->ownedDocument($document);

        $groupId = $document->group_id;
        $title = $document->title;

        DB::transaction(function () use ($document) {
            $document->forceFill(['deleted_by' => auth()->id()])->save();

            $this->recorder->documentDeleted($document, auth()->id());

            $document->delete();
        });

        return redirect()->route('student.documents.group', $groupId)->with(
            'status',
            "“{$title}” has been removed from your group. Your lecturer can still see it.",
        );
    }
    /*
    |--------------------------------------------------------------------------
    | Versions
    |--------------------------------------------------------------------------
    */

    /** Read-only view of a past version. */
    public function version(Document $document, DocumentVersion $version): View
    {
        $document = $this->ownedDocument($document);
        $version = $this->ownedVersion($document, $version);

        return view('student.documents.version', [
            'group' => $document->group->load('subject'),
            'document' => $document,
            'version' => $version->load('editor'),
            'versions' => $document->versions()->with('editor')->get(),
            'body' => $this->sanitizer->render($version->content),
            'isCurrent' => $this->sanitizer->isUnchanged($document->content, $version->content),
        ]);
    }

    /**
     * Restores a past version.
     *
     * The current body is snapshotted as its own version first when it is not
     * already the newest one, then the chosen version is applied as a further
     * version — so a restore only ever adds to the history and nothing is lost.
     */
    public function restore(Document $document, DocumentVersion $version): RedirectResponse
    {
        $document = $this->ownedDocument($document);
        $version = $this->ownedVersion($document, $version);

        if ($this->sanitizer->isUnchanged($document->content, $version->content)) {
            return redirect()->route('student.documents.edit', $document)
                ->with('status', "Version {$version->version_no} is already the current content.");
        }

        $newest = $document->versions()->first();

        // Normally the body already equals the newest version, because every
        // save writes one. Snapshot only if it has drifted, so a restore does
        // not add a duplicate row (which would score twice for no writing).
        if ($newest === null || ! $this->sanitizer->isUnchanged($document->content, $newest->content)) {
            $this->writeVersion($document, (string) $document->content);
        }

        $restored = $this->writeVersion($document, (string) $version->content);

        $document->forceFill([
            'content' => $version->content,
            'last_edited_by' => auth()->id(),
        ])->save();

        $this->recorder->documentEdited($document, auth()->id());
        $this->notifier->documentEdited($document, auth()->user());

        return redirect()->route('student.documents.edit', $document)->with(
            'status',
            "Version {$version->version_no} restored as version {$restored->version_no}. Nothing was lost — the previous body is still in the history.",
        );
    }

    /**
     * Where to land after a save. "Save and close" returns to the document
     * list, which is what someone wants once they have finished writing;
     * plain "Save" keeps them in the editor.
     */
    protected function afterSave(Request $request, Document $document, string $message): RedirectResponse
    {
        if ($request->filled('close')) {
            return redirect()->route('student.documents.group', $document->group_id)
                ->with('status', $message);
        }

        return back()->with('status', $message);
    }

    /** Appends the next version number for this document. */
    protected function writeVersion(Document $document, string $content): DocumentVersion
    {
        return DocumentVersion::create([
            'document_id' => $document->id,
            'edited_by' => auth()->id(),
            'version_no' => (int) $document->versions()->max('version_no') + 1,
            'content' => $content,
        ]);
    }
}
