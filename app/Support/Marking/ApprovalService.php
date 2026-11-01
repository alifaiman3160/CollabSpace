<?php

namespace App\Support\Marking;

use App\Models\Group;
use App\Models\MarkApproval;
use App\Models\MarkingRun;
use App\Models\Rubric;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approving, and un-approving, one student's marks.
 *
 * Approving freezes the sheet. The approval keeps its own copy of every level,
 * mark, suggestion, reason and fact it rests on, and of the provider's prompt and
 * reply, so nothing that happens later (more activity, a re-run of suggestions, a
 * changed rubric setting) can move a signed-off mark. It is refused unless every
 * section is complete: every criterion has a level the lecturer chose, including
 * Manual ones and ones the group had too little activity for.
 *
 * Revoking keeps the approval, stamped with who and when, and reopens the choices.
 */
class ApprovalService
{
    public function __construct(protected MarkSheet $sheets) {}

    /** @throws MarkingException */
    public function approve(User $by, Rubric $rubric, Group $group, User $student): MarkApproval
    {
        return DB::transaction(function () use ($by, $rubric, $group, $student): MarkApproval {
            $exists = MarkApproval::active()
                ->where('rubric_id', $rubric->id)->where('group_id', $group->id)->where('student_id', $student->id)
                ->lockForUpdate()->exists();

            if ($exists) {
                throw new MarkingException('These marks are already approved.');
            }

            $sheet = $this->sheets->build($rubric, $group, $student);

            if (! $sheet['complete']) {
                throw new MarkingException('These marks cannot be approved yet.', $sheet['blockers']);
            }

            $snapshot = $this->sheets->snapshot($rubric, $sheet);
            // Context only, like the group's name: which class section these marks were approved for.
            $snapshot['class_section'] = $group->section?->name;

            return MarkApproval::create([
                'rubric_id' => $rubric->id,
                'group_id' => $group->id,
                'student_id' => $student->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'snapshot' => $snapshot,
                'audit' => $this->audit($snapshot),
                'total' => $sheet['total'],
            ]);
        });
    }

    /** @throws MarkingException */
    public function revoke(User $by, Rubric $rubric, Group $group, User $student): MarkApproval
    {
        $approval = MarkApproval::active()
            ->where('rubric_id', $rubric->id)->where('group_id', $group->id)->where('student_id', $student->id)
            ->latest('id')->first();

        if ($approval === null) {
            throw new MarkingException('There is no approval to revoke.');
        }

        $approval->update(['revoked_by' => $by->id, 'revoked_at' => now()]);

        return $approval;
    }

    /**
     * The provider record behind the suggestions, copied in: provider, model, the
     * instructions and anonymised data sent, and the reply as received.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{runs: list<array<string, mixed>>}
     */
    protected function audit(array $snapshot): array
    {
        $ids = collect($snapshot['sections'])->flatMap(fn ($s) => collect($s['criteria'])->pluck('run_id'))->filter()->unique()->values();

        return [
            'runs' => MarkingRun::whereIn('id', $ids)->get()->map(fn (MarkingRun $run) => [
                'id' => $run->id,
                'provider' => $run->provider,
                'model' => $run->model,
                'status' => $run->status,
                'prompt' => $run->prompt,
                'response' => $run->response,
                'at' => $run->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
