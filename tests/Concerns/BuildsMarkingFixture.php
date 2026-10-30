<?php

namespace Tests\Concerns;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Rubric;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use App\Support\Rubric\RubricParser;
use App\Support\Rubric\RubricStore;

/**
 * A group with a task board and recorded activity, and the sample rubric saved
 * for its subject, so facts and suggestions can be checked against known figures.
 *
 * Four students, each with identifying details that must never reach an AI
 * provider (distinctive names, emails, matric numbers and CGPAs):
 *
 *   s1  4 tasks assigned: 2 completed on time, 1 completed 3 days late, 1 overdue
 *       now. 10 messages, 3 document edits, 1 file.
 *   s2  3 tasks assigned: 2 completed on time with credit recorded, 1 marked done
 *       with no credit recorded (so it is not evidence). 4 messages, 1 edit.
 *   s3  nothing assigned. 0 messages.
 *   s4  1 task assigned, open, no deadline. 6 messages.
 *
 * Group averages: 2 tasks, 5 messages, 1 document edit, 0.25 files.
 */
trait BuildsMarkingFixture
{
    protected User $lecturer;

    protected Subject $subject;

    protected Group $group;

    /** @var array<string, User> */
    protected array $students = [];

    protected Rubric $rubric;

    protected function buildMarkingFixture(bool $withRubric = true): void
    {
        // This group is small on purpose, so most tests run with the minimum-activity
        // guard off to reason about exact ratios. The guard has its own tests, which
        // switch it back on (see MinimumActivityTest).
        config(['marking.min_group_activity' => ['task_completion' => 0, 'reliability' => 0, 'communication' => 0, 'collaboration' => 0]]);

        $this->lecturer = User::factory()->lecturer()->create(['name' => 'Dr Zainab Lecturer', 'must_change_password' => false]);

        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4,
        ]);

        $this->group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Group Falcon', 'group_size' => 4]);

        $people = [
            's1' => ['Aminah Binti Rashid', 'aminah.rashid@students.test', 'A21EC1111', 3.91],
            's2' => ['Bakri Bin Hamdan', 'bakri.hamdan@students.test', 'A21EC2222', 3.12],
            's3' => ['Chong Mei Ling', 'chong.meiling@students.test', 'A21EC3333', 2.74],
            's4' => ['Devika Nair', 'devika.nair@students.test', 'A21EC4444', 3.55],
        ];

        foreach ($people as $key => [$name, $email, $matric, $cgpa]) {
            $this->students[$key] = User::factory()->student()->create([
                'name' => $name, 'email' => $email, 'matric_no' => $matric, 'cgpa' => $cgpa, 'must_change_password' => false,
            ]);

            GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->students[$key]->id, 'joined_at' => now()]);
        }

        $this->recordActivity();

        if ($withRubric) {
            $this->rubric = $this->saveSampleRubric();
        }
    }

    protected function recordActivity(): void
    {
        $recorder = app(ActivityRecorder::class);
        [$s1, $s2, $s3, $s4] = array_values($this->students);

        $task = function (User $who, string $status, ?string $deadline, ?\DateTimeInterface $completedAt = null, bool $credited = true) use ($recorder): Task {
            $t = Task::create([
                'group_id' => $this->group->id, 'created_by' => $this->lecturer->id, 'assigned_to' => $who->id,
                'title' => 'A task', 'status' => $status, 'deadline' => $deadline, 'completed_at' => $completedAt,
            ]);

            if ($status === Task::STATUS_DONE && $credited) {
                $recorder->taskCompleted($t, $who);
            }

            return $t;
        };

        // s1
        $task($s1, 'done', now()->addDays(5)->toDateString(), now());
        $task($s1, 'done', null, now());
        $task($s1, 'done', now()->subDays(3)->toDateString(), now());          // 3 days late
        $task($s1, 'todo', now()->subDays(2)->toDateString());                 // overdue now

        // s2
        $task($s2, 'done', now()->toDateString(), now());
        $task($s2, 'done', null, now());
        $task($s2, 'done', null, now(), credited: false);                      // done, credit withdrawn

        // s4
        $task($s4, 'todo', null);

        foreach ([[$s1, 10], [$s2, 4], [$s4, 6]] as [$who, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $recorder->record($who, $this->group, 'message_sent');
            }
        }

        for ($i = 0; $i < 3; $i++) {
            $recorder->record($s1, $this->group, 'document_edited');
        }
        $recorder->record($s2, $this->group, 'document_created');
        $recorder->record($s1, $this->group, 'file_uploaded');
    }

    /** Messages as the running app records them, through the daily cap. */
    protected function sendMessages(User $who, int $count): void
    {
        $recorder = app(ActivityRecorder::class);

        for ($i = 0; $i < $count; $i++) {
            $message = Message::create(['group_id' => $this->group->id, 'user_id' => $who->id, 'body' => 'hello']);
            $recorder->messageSent($message);
        }
    }

    /**
     * The sample rubric, saved for the subject, with the teamwork section's
     * criteria given the evidence the wording suggests.
     */
    protected function saveSampleRubric(?callable $edit = null): Rubric
    {
        $draft = (new RubricParser)->parse(__DIR__.'/../../docs/samples/MarkingRubric.xlsx', 'MarkingRubric.xlsx');
        $data = ['name' => $draft['name'], 'sections' => $draft['sections']];

        if ($edit) {
            $data = $edit($data);
        }

        return app(RubricStore::class)->create($this->subject, $this->lecturer, $data, 'MarkingRubric.xlsx')
            ->load('sections.criteria.levels');
    }

    /**
     * A rationale such as a good answer gives: it cites the first figure of the
     * criterion's own facts, and for reliability names overdue work. $criterion is
     * one entry of a request's payload.
     *
     * @param  array<string, mixed>  $criterion
     */
    protected function cite(array $criterion, string $text = 'Based on the supplied counts'): string
    {
        $first = null;
        $facts = $criterion['facts'] ?? [];

        array_walk_recursive($facts, function ($value) use (&$first) {
            if ($first === null && is_numeric($value)) {
                $first = $value;
            }
        });

        $sentence = "{$text} ({$first})";

        if (($criterion['evidence'] ?? null) === 'reliability') {
            $sentence .= ', including any overdue tasks';
        }

        return $sentence.'.';
    }

    /** The saved criterion with this name. */
    protected function criterion(string $name, ?Rubric $rubric = null): \App\Models\RubricCriterion
    {
        return ($rubric ?? $this->rubric)->criteria()->where('rubric_criteria.name', $name)->with('levels')->firstOrFail();
    }

    /*
    | Marking helpers
    */

    /** The id of a saved criterion's level by label. */
    protected function levelId(string $criterion, string $label, ?Rubric $rubric = null): int
    {
        return $this->criterion($criterion, $rubric)->levels->firstWhere('label', $label)->id;
    }

    /**
     * Saves choices for a student through the recorder, as the marking screen does.
     * $choices maps a criterion name to a level label (null clears it); $reasons a
     * criterion name to a reason; $methods a section id to add|scale.
     *
     * @return array<string, string> the recorder's errors
     */
    protected function choose(string $student, array $choices, array $reasons = [], array $methods = [], ?Rubric $rubric = null): array
    {
        $rubric ??= $this->rubric;

        $levels = [];
        foreach ($choices as $name => $label) {
            $criterion = $this->criterion($name, $rubric);
            $levels[$criterion->id] = $label === null ? '' : $criterion->levels->firstWhere('label', $label)->id;
        }

        $reasonsById = [];
        foreach ($reasons as $name => $reason) {
            $reasonsById[$this->criterion($name, $rubric)->id] = $reason;
        }

        return app(\App\Support\Marking\ChoiceRecorder::class)->save(
            $this->lecturer, $rubric, $this->group, $this->students[$student], $levels, $reasonsById, $methods,
        );
    }

    /** Every criterion of the rubric at the level with this label ("Excellent" by default). */
    protected function chooseEverything(string $student, string $label = 'Excellent', ?Rubric $rubric = null, array $methods = []): array
    {
        $rubric ??= $this->rubric;
        $rubric->loadMissing('sections.criteria');

        $names = $rubric->sections->flatMap->criteria->pluck('name')->all();

        // A reason for each, so choosing over a suggestion is never what stops this helper.
        $errors = $this->choose($student, array_fill_keys($names, $label), array_fill_keys($names, 'Chosen by the lecturer.'), $methods, $rubric);

        $this->assertSame([], $errors, 'chooseEverything was refused.');

        return $errors;
    }
}
