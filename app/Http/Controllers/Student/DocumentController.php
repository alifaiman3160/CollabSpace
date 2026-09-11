<?php

namespace App\Http\Controllers\Student;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Support\ActivityRecorder;
use App\Support\DocumentSanitizer;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return view('student.documents.index', [
            'hasGroup' => true,
            'groups' => $this->myGroups(),
            'group' => $group->load('subject'),
            'documents' => $group->documents()
                ->with('lastEditor')
                ->withCount('versions')
                ->orderByDesc('updated_at')
                ->get(),
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
    | Delete
    |--------------------------------------------------------------------------
    */

    /**
     * Deletes a document, its whole version history and the contribution it
     * earned. Use case 3.4.3.5, alternative flow A2.
     *
     * The version rows cascade from the foreign key; the activity_logs rows do
     * not, because reference_id is not a foreign key, so the recorder clears
     * them explicitly. Both sit in one transaction: a document whose history
     * survived it, or whose score did, would be worse than either outcome.
     *
     * Any member of the group may do this — the same rule the rest of the
     * module runs on, since a group document belongs to the group rather than
     * to whoever typed it first. The UI confirms, with the version count it is
     * about to destroy.
     */
    public function destroy(Document $document): RedirectResponse
    {
        $document = $this->ownedDocument($document);

        $groupId = $document->group_id;
        $title = $document->title;
        $versions = $document->versions()->count();

        DB::transaction(function () use ($document) {
            $this->recorder->documentDeleted($document);

            $document->delete();
        });

        return redirect()->route('student.documents.group', $groupId)->with(
            'status',
            "“{$title}” has been deleted, along with ".$versions.' '.str('version')->plural($versions).' of its history.',
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
