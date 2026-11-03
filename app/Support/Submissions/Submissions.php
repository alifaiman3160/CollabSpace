<?php

namespace App\Support\Submissions;

use App\Models\Assessment;
use App\Models\Document;
use App\Models\Group;
use App\Models\Submission;
use App\Models\User;
use App\Support\ActivityRecorder;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A group submitting its report for an assessment: one frozen PDF of the chosen documents, kept on the private disk.
 * Evidence and display only: nothing here touches marking, the rubric, contribution points or the windows.
 *
 * The file is written first and the rows second, in one transaction; if the rows fail the file is removed, and if the
 * PDF cannot be made no row exists. Earlier and withdrawn submissions stay as history, with their files.
 */
class Submissions
{
    public const DISK = 'local';

    public const MAX_DOCUMENTS = 10;

    public function __construct(
        protected SubmissionPdf $pdf,
        protected ActivityRecorder $recorder,
        protected Notifier $notifier,
    ) {}

    public static function directory(Group|int $group): string
    {
        return 'submissions/'.($group instanceof Group ? $group->getKey() : $group);
    }

    /** True while the assessment can still be handed in on time (and withdrawn): up to the end of its due date. */
    public static function beforeDeadline(Assessment $assessment): bool
    {
        $end = $assessment->windowEnd();

        return $end === null || now()->lte($end);
    }

    /**
     * @param  list<int>  $documentIds  in the order they go into the PDF
     */
    public function submit(Group $group, Assessment $assessment, array $documentIds, ?string $note, User $by): Submission
    {
        $group->loadMissing('subject');

        // Only an assessment of the group's own subject.
        if ($assessment->subject_id !== $group->subject_id) {
            throw new SubmissionException('Choose one of this subject\'s assessments.');
        }

        $ids = collect($documentIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            throw new SubmissionException('Choose at least one document to submit.');
        }

        if ($ids->count() > self::MAX_DOCUMENTS) {
            throw new SubmissionException('A submission can hold at most '.self::MAX_DOCUMENTS.' documents.');
        }

        // The group's own documents that are not deleted; anything else is refused, not skipped.
        $found = Document::query()->where('group_id', $group->getKey())->whereIn('id', $ids)->get()->keyBy('id');

        if ($found->count() !== $ids->count()) {
            throw new SubmissionException('One of the chosen documents is not available any more. Reload the page and choose again.');
        }

        $documents = $ids->map(fn ($id) => $found[$id]);

        $at = now();
        $late = ! self::beforeDeadline($assessment);
        $hadCurrent = Submission::currentFor($group->getKey(), $assessment->getKey()) !== null;

        try {
            $built = $this->pdf->render($group, $assessment, $documents, $by, $at, $late);
        } catch (SubmissionException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new SubmissionException('The PDF could not be prepared, so nothing was submitted. Please try again in a moment.');
        }

        $disk = Storage::disk(self::DISK);
        $path = self::directory($group).'/'.Str::random(40).'.pdf';

        try {
            if (! $disk->put($path, $built['output'])) {
                throw new SubmissionException('The PDF could not be saved, so nothing was submitted. Please try again.');
            }

            $submission = DB::transaction(function () use ($group, $assessment, $by, $at, $late, $path, $built, $note, $documents, $hadCurrent) {
                $submission = Submission::create([
                    'group_id' => $group->getKey(),
                    'assessment_id' => $assessment->getKey(),
                    'submitted_by' => $by->getKey(),
                    'submitted_at' => $at,
                    'is_late' => $late,
                    'pdf_path' => $path,
                    'pdf_size' => strlen($built['output']),
                    'page_count' => $built['pages'],
                    'note' => filled($note) ? Str::limit(trim($note), 255, '') : null,
                ]);

                foreach ($documents as $position => $document) {
                    $submission->documents()->create(['document_id' => $document->getKey(), 'title' => $document->title, 'position' => $position]);
                }

                $this->recorder->record($by, $group, $hadCurrent ? 'submission_resubmitted' : 'submission_submitted', $submission->getKey(), $at);

                return $submission;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            if ($e instanceof SubmissionException) {
                throw $e;
            }

            report($e);

            throw new SubmissionException('The submission could not be saved. Please try again.');
        }

        $this->notifier->submissionMade($submission->load('assessment', 'group.subject'), $by);

        return $submission;
    }

    /** Withdraws the current submission; its row and PDF stay as history. Refused once the due date has ended. */
    public function withdraw(Submission $submission, User $by): Submission
    {
        $submission->loadMissing('assessment', 'group');

        if ($submission->isWithdrawn()) {
            throw new SubmissionException('That submission is already withdrawn.');
        }

        if (Submission::currentFor($submission->group_id, $submission->assessment_id)?->getKey() !== $submission->getKey()) {
            throw new SubmissionException('Only the current submission can be withdrawn.');
        }

        if (! self::beforeDeadline($submission->assessment)) {
            throw new SubmissionException('The due date has passed, so this submission can no longer be withdrawn.');
        }

        $submission->forceFill(['withdrawn_at' => now(), 'withdrawn_by' => $by->getKey()])->save();

        $this->recorder->record($by, $submission->group_id, 'submission_withdrawn', $submission->getKey());
        $this->notifier->submissionWithdrawn($submission, $by);

        return $submission;
    }
}
