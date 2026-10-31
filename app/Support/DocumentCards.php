<?php

namespace App\Support;

use App\Models\Group;
use Illuminate\Database\Eloquent\Collection;

/**
 * A group's documents as the card grid needs them: ONE query for the documents (only the head of each body, and the
 * number of page breaks counted in the database), with the editor, creator and version count alongside. Never a whole
 * body. DocumentPreview turns each row into the small first page.
 */
class DocumentCards
{
    /**
     * @param  bool  $deleted  the group's deleted documents (for a lecturer) instead of the live ones
     * @return Collection<int, \App\Models\Document>
     */
    public static function forGroup(Group $group, bool $deleted = false): Collection
    {
        $marker = 'data-page-break';
        $columns = ['documents.id', 'documents.group_id', 'documents.created_by', 'documents.last_edited_by', 'documents.title', 'documents.created_at', 'documents.updated_at'];

        $query = $deleted ? $group->documents()->onlyTrashed() : $group->documents();

        if ($deleted) {
            $columns = [...$columns, 'documents.deleted_at', 'documents.deleted_by'];
        }

        return $query
            ->select($columns)
            ->selectRaw('substr(coalesce(documents.content, \'\'), 1, ?) as content_head', [DocumentPreview::HEAD])
            ->selectRaw('(length(coalesce(documents.content, \'\')) - length(replace(coalesce(documents.content, \'\'), ?, \'\'))) / ? as page_breaks', [$marker, strlen($marker)])
            ->with(['lastEditor:id,name', 'creator:id,name', ...($deleted ? ['deleter:id,name'] : [])])
            ->withCount('versions')
            ->orderByDesc($deleted ? 'documents.deleted_at' : 'documents.updated_at')
            ->get();
    }
}