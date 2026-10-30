<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Support\DocumentSanitizer;
use Illuminate\View\View;

/**
 * Read-only access to a group's documents, for the lecturer who supervises it.
 *
 * Until this existed the monitoring screen could say that a document had been
 * edited eleven times but not what it said, so judging the work still meant
 * asking the group to email it — and the contribution report counted edits
 * without anyone being able to see what those edits produced.
 *
 * Read-only is the whole point, and it is enforced by there being nothing else
 * here: no store, no update, no restore, no destroy. A lecturer observes the
 * group's writing; they do not write it. Authorship stays with the students,
 * and so does the contribution credit.
 */
class DocumentController extends LecturerController
{
    public function __construct(protected DocumentSanitizer $sanitizer) {}

    /**
     * Resolves a document through the lecturer's own groups.
     *
     * The group is what ownership actually runs through — lecturer owns
     * subject, subject owns group, group owns document — so a document id
     * belonging to another lecturer's cohort 404s exactly like everything else
     * in this area.
     */
    protected function ownedDocument(Document $document): Document
    {
        $document->loadMissing('group.subject');

        abort_if($document->group === null, 404);

        $this->ownedGroup($document->group);

        return $document;
    }

    /** The group's documents, listed for monitoring. */
    public function index(Group $group): View
    {
        $group = $this->ownedGroup($group);

        return view('lecturer.documents.index', [
            'group' => $group->load('subject'),
            'documents' => $group->documents()
                ->with(['lastEditor', 'creator'])
                ->withCount('versions')
                ->orderByDesc('updated_at')
                ->get(),
        ]);
    }

    /** The current body of one document. */
    public function show(Document $document): View
    {
        $document = $this->ownedDocument($document);

        return view('lecturer.documents.show', [
            'group' => $document->group,
            'document' => $document->load('lastEditor'),
            'versions' => $document->versions()->with('editor')->get(),
            'version' => null,
            'body' => $this->sanitizer->render($document->content),
        ]);
    }

    /**
     * A past version.
     *
     * Worth having for marking rather than curiosity: the history is what shows
     * who actually wrote the chapter, as opposed to who happened to save it
     * last and therefore appears as its editor on the list.
     */
    public function version(Document $document, DocumentVersion $version): View
    {
        $document = $this->ownedDocument($document);

        abort_unless($version->document_id === $document->getKey(), 404);

        return view('lecturer.documents.show', [
            'group' => $document->group,
            'document' => $document->load('lastEditor'),
            'versions' => $document->versions()->with('editor')->get(),
            'version' => $version->load('editor'),
            'body' => $this->sanitizer->render($version->content),
        ]);
    }
}
