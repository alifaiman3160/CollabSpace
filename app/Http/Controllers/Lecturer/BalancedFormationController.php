<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Group;
use App\Models\Subject;
use App\Support\Formation\BalancedAllocator;
use App\Support\Formation\FormationException;
use App\Support\Formation\FormationSession;
use App\Support\Formation\GroupFormation;
use App\Support\Formation\Pool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mode 1: balanced allocation by CGPA.
 *
 * Three steps, and only the last one writes anything:
 *
 *   preview     deals the chosen students into groups and holds the result in
 *               the session. Nothing is saved.
 *   regenerate  deals again with a different seed. Nothing is saved.
 *   confirm     saves exactly the proposal that was held, after checking it is
 *               still valid. This is the only action that touches the database.
 *
 * Everything works on the proposal held in the session rather than on what the
 * browser sends back, so what the lecturer saw and approved is what is saved.
 */
class BalancedFormationController extends LecturerController
{
    protected const KIND = 'balanced';

    public function __construct(
        protected BalancedAllocator $allocator,
        protected FormationSession $held,
        protected GroupFormation $formation,
    ) {}

    public function preview(Request $request, Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $pool = Pool::for($subject)->keyBy('id');

        $validated = $request->validate([
            'students' => ['required', 'array', 'min:2'],
            'students.*' => ['integer'],
            'groups' => ['required', 'integer', 'min:1', 'max:50'],
            'prefix' => ['nullable', 'string', 'max:30'],
        ], [
            'students.required' => 'Choose the students to place.',
            'students.min' => 'Choose at least two students — a group needs more than one person.',
        ]);

        // Only people who can actually still be placed. An id that is not in
        // the pool (already in a group, deactivated, someone else's) is dropped
        // rather than trusted.
        $chosen = $pool->only($validated['students'])->values();

        if ($chosen->count() < 2) {
            return back()->withInput()->withErrors(['students' => 'Choose at least two students who still have no group.']);
        }

        $bounds = BalancedAllocator::bounds($chosen->count(), $subject->group_size);
        $groups = (int) $validated['groups'];

        if ($groups < $bounds['min'] || $groups > $bounds['max']) {
            return back()->withInput()->withErrors(['groups' => $this->boundsMessage($chosen->count(), $subject->group_size, $bounds)]);
        }

        $students = $chosen->map(fn ($u) => Pool::snapshot($u))->all();
        $prefix = trim((string) ($validated['prefix'] ?? '')) ?: 'Group';

        $token = $this->held->put(self::KIND, $subject->getKey(), [
            'students' => $students,
            'groups' => $groups,
            'prefix' => $prefix,
            // Seed 0 is the plain canonical snake. Only regenerating varies it.
            'proposal' => $this->propose($subject, $students, $groups, 0, $prefix),
        ]);

        return redirect()->route('lecturer.formation.balanced.show', [$subject, $token]);
    }

    public function show(Subject $subject, string $token): View|RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null) {
            return $this->expired($subject);
        }

        return view('lecturer.formation.balanced', [
            'subject' => $subject,
            'token' => $token,
            'proposal' => $held['proposal'],
            'students' => $held['students'],
            'prefix' => $held['prefix'],
            'tolerance' => \App\Support\Formation\SnakeDraft::TIE_TOLERANCE,
        ]);
    }

    public function regenerate(Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null) {
            return $this->expired($subject);
        }

        // A new seed each time. Never the one already showing: pressing the
        // button and seeing the same groups would look like it did nothing.
        do {
            $seed = random_int(1, 2_000_000_000);
        } while ($seed === ($held['proposal']['seed'] ?? 0));

        $held['proposal'] = $this->propose($subject, $held['students'], $held['groups'], $seed, $held['prefix']);

        $this->held->replace(self::KIND, $token, $held);

        return redirect()->route('lecturer.formation.balanced.show', [$subject, $token]);
    }

    public function confirm(Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null) {
            return $this->expired($subject);
        }

        $proposal = $held['proposal'];

        $plans = array_map(fn (array $group) => [
            'name' => $group['name'],
            'size' => max(2, $subject->group_size),
            'member_ids' => array_column($group['members'], 'id'),
            // A snapshot of how it looked when it was formed. CGPAs are edited
            // later, and "was this group balanced when it was made" has to keep
            // the answer it had.
            'meta' => [
                'algorithm' => 'snake_draft',
                'seed' => $proposal['seed'],
                'average_cgpa' => $group['average'],
                'cgpa_known' => $group['known'],
                'cgpa_unknown' => $group['unknown'],
                'spread_across_batch' => $proposal['spread'],
                'cgpa_entered_manually' => true,
            ],
        ], $proposal['groups']);

        try {
            $created = $this->formation->create($subject, auth()->user(), Group::FORMED_BALANCED, $plans);
        } catch (FormationException $e) {
            // The preview no longer matches reality, so it is discarded: the
            // lecturer would otherwise be one click from confirming something
            // that just failed. Nothing was saved.
            $this->held->forget(self::KIND, $token);

            return redirect()->route('lecturer.formation', $subject)->withErrors(['status' => $e->getMessage()]);
        }

        $this->held->forget(self::KIND, $token);

        $students = array_sum(array_map(fn (Group $g) => $g->groupMembers()->count(), $created));

        return redirect()->route('lecturer.groups', ['subject' => $subject->getKey()])
            ->with('status', 'Created '.count($created).' balanced '.str('group')->plural(count($created))
                .' with '.$students.' '.str('student')->plural($students).'. Each student has been notified.');
    }

    /** The preview was lost: the session ended, or it was pushed out by newer ones. */
    protected function expired(Subject $subject): RedirectResponse
    {
        return redirect()->route('lecturer.formation', $subject)
            ->withErrors(['status' => 'That preview has expired, so nothing was saved. Set it up again.']);
    }

    /**
     * @param  list<array{id: int, name: string, matric_no: ?string, cgpa: ?float}>  $students
     */
    protected function propose(Subject $subject, array $students, int $groups, int $seed, string $prefix): array
    {
        $taken = Group::query()->where('subject_id', $subject->getKey())->pluck('name')->all();

        return $this->allocator->propose($students, $groups, $seed, $prefix, $taken);
    }

    /** @param  array{min: int, max: int}  $bounds */
    protected function boundsMessage(int $students, int $limit, array $bounds): string
    {
        $range = $bounds['min'] === $bounds['max']
            ? "exactly {$bounds['min']}"
            : "between {$bounds['min']} and {$bounds['max']}";

        return "{$students} students with a limit of {$limit} per group need {$range} "
            .str('group')->plural($bounds['max']).'.';
    }
}
