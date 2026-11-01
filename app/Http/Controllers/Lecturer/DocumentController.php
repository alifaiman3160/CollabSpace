<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Support\DocumentCards;
use App\Support\DocumentSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Read-only access to a group's documents, for the lecturer who supervises it. They live under Monitor progress.
 *
 * Until this existed the monitoring screen could say that a document had been edited eleven times but not what it
 * said, so judging the work still meant asking the group to email it.
 *
 * Read-only is the whole point, and it is enforced by there being nothing else here: no store, no update, no restore,
 * no destroy, and nothing records activity. A lecturer observes the group's writing; they do not write it.
 * A document the group deleted is still readable here, with a banner saying who removed it and when.
 */
class DocumentController extends LecturerController
{
    public function __construct(protected DocumentSanitizer $sanitizer) {}

    /**
     * Resolves a document through the lecturer's own groups: lecturer owns subject, subject owns group, group owns
     * document, so a document id from another lecturer's cohort 404s like everything else in this area.
     */
    protected function ownedDocument(Document $document): Document
    {
        $document->loadMissing('group.subject');

        abort_if($document->group === null, 404);

        $this->ownedGroup($document->group);

        return $document;
    }

    /** The document must be this group's: an id that belongs to another group is not found here. */
    protected function inGroup(Group $group, Document $document): Document
    {
        $group = $this->ownedGroup($group);

        abort_unless($document->group_id === $group->getKey(), 404);

        $document->setRelation('group', $group->loadMissing('subject'));

        return $document;
    }

    /** The group's documents, as cards. */
    public function index(Group $group): View
    {
        $group = $this->ownedGroup($group)->load(['subject', 'section']);

        return view('lecturer.documents.index', [
            'group' => $group,
            'documents' => DocumentCards::forGroup($group),
            'deleted' => DocumentCards::forGroup($group, deleted: true),
        ]);
    }

    /** The current body of one document. */
    public function show(Group $group, Document $document): View
    {
        $document = $this->inGroup($group, $document);

        return view('lecturer.documents.show', [
            'group' => $document->group,
            'document' => $document->load(['lastEditor', 'deleter']),
            'versions' => $document->versions()->with('editor')->get(),
            'version' => null,
            'body' => $this->sanitizer->render($document->content),
        ]);
    }

    /**
     * A past version.
     *
     * Worth having for marking rather than curiosity: the history is what shows who actually wrote the chapter, as
     * opposed to who happened to save it last and therefore appears as its editor on the list.
     */
    public function version(Group $group, Document $document, DocumentVersion $version): View
    {
        $document = $this->inGroup($group, $document);

        abort_unless($version->document_id === $document->getKey(), 404);

        return view('lecturer.documents.show', [
            'group' => $document->group,
            'document' => $document->load(['lastEditor', 'deleter']),
            'versions' => $document->versions()->with('editor')->get(),
            'version' => $version->load('editor'),
            'body' => $this->sanitizer->render($version->content),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The old addresses
    |--------------------------------------------------------------------------
    */

    public function legacyIndex(Group $group): RedirectResponse
    {
        return redirect()->route('lecturer.progress.documents', $this->ownedGroup($group), 301);
    }

    public function legacyShow(Document $document): RedirectResponse
    {
        $document = $this->ownedDocument($document);

        return redirect()->route('lecturer.progress.documents.show', [$document->group, $document], 301);
    }

    public function legacyVersion(Document $document, DocumentVersion $version): RedirectResponse
    {
        $document = $this->ownedDocument($document);

        abort_unless($version->document_id === $document->getKey(), 404);

        return redirect()->route('lecturer.progress.documents.version', [$document->group, $document, $version], 301);
    }
}