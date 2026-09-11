<?php

namespace App\Support;

use App\Models\Group;
use Illuminate\Support\Facades\Storage;

/**
 * Where a group's chat uploads live, and how they are disposed of.
 *
 * Uploads are written to storage/app/private/group-files/{group id}, which is
 * never web-served — ChatController is the only route to them. The database
 * rows cascade when a group or a subject is deleted, but a cascade cannot touch
 * the filesystem, so without this the files outlived everything that referenced
 * them: unreachable, unaccounted for, and still holding the group's work on
 * disk indefinitely.
 *
 * One place, so the path the uploader writes to and the path the cleanup
 * removes can never drift apart.
 */
class GroupFiles
{
    /** The disk uploads live on. storage/app/private — never web-served. */
    public const DISK = 'local';

    public static function directoryFor(Group|int $group): string
    {
        return 'group-files/'.($group instanceof Group ? $group->getKey() : $group);
    }

    /**
     * Removes every uploaded file belonging to a group, and the directory
     * itself.
     *
     * Safe to call for a group that never had an upload: deleteDirectory()
     * reports false for a directory that is not there, and there is nothing to
     * do about that.
     */
    public function purge(Group|int $group): bool
    {
        return Storage::disk(self::DISK)->deleteDirectory(self::directoryFor($group));
    }
}
