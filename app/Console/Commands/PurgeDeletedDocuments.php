<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Support\ActivityRecorder;
use App\Support\GroupFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes documents that were deleted more than N days ago: the row, its versions, its pictures (rows and
 * files on disk) and the activity that referred to it, and nothing else.
 *
 * Deliberately blunt: --days has to be given (there is no default, so nobody purges by accident), --dry-run lists what
 * would go, and nothing schedules it. A purge cannot be undone, and it also removes the document's contribution credit,
 * so it is for housekeeping after marking is finished, not part of normal use. See docs/BACKUP.md.
 */
class PurgeDeletedDocuments extends Command
{
    protected $signature = 'documents:purge-deleted {--days= : Remove documents deleted more than this many days ago (required)} {--dry-run : List what would be removed and remove nothing}';

    protected $description = 'Permanently remove soft-deleted documents (with their versions and pictures) older than N days';

    public function handle(ActivityRecorder $recorder): int
    {
        $days = $this->option('days');

        if ($days === null || $days === '' || ! ctype_digit((string) $days)) {
            $this->error('--days is required, as a whole number (for example --days=90). Nothing was removed.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) $days);
        $dry = (bool) $this->option('dry-run');

        $documents = Document::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->withCount(['versions', 'images'])
            ->orderBy('deleted_at')
            ->get();

        if ($documents->isEmpty()) {
            $this->info("No document was deleted more than {$days} days ago.");

            return self::SUCCESS;
        }

        $this->line(($dry ? 'Would remove' : 'Removing').' '.$documents->count().' '.str('document')->plural($documents->count()).' deleted before '.$cutoff->format('j F Y, g:ia').':');

        foreach ($documents as $document) {
            $this->line(sprintf('  #%d  %s  (deleted %s; %d versions, %d pictures)',
                $document->id, $document->title, $document->deleted_at->format('j M Y'), $document->versions_count, $document->images_count));
        }

        if ($dry) {
            $this->info('Dry run: nothing was removed.');

            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            DB::transaction(function () use ($document, $recorder) {
                $directory = GroupFiles::directoryFor($document->group_id).'/documents/'.$document->id;

                $recorder->documentPurged($document);
                $document->forceDelete();   // the versions and picture rows follow by cascade

                Storage::disk(GroupFiles::DISK)->deleteDirectory($directory);
            });
        }

        $this->info('Removed '.$documents->count().' '.str('document')->plural($documents->count()).'.');

        return self::SUCCESS;
    }
}