<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Group;
use App\Models\Section;
use App\Models\Subject;
use App\Support\Formation\CsvReader;
use App\Support\Formation\FormationException;
use App\Support\Formation\FormationSession;
use App\Support\Formation\GroupFormation;
use App\Support\Formation\ImportBuilder;
use App\Support\Formation\ImportReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mode 2: importing groups the students chose for themselves, from a Google
 * Form export.
 *
 * Four screens, and none of them writes a group:
 *
 *   upload    the file and which shape of form it came from
 *   columns   which column is the group name and which hold the members
 *   review    everything that parsed, everything that did not, and the
 *             lecturer's decisions about each problem
 *   confirm   the one action that creates groups, and only for what is clean
 *
 * The review screen is the heart of it. Every problem is shown, none is
 * guessed at, and nothing is created until the whole import is clean or the
 * lecturer explicitly chooses to leave the problem groups out.
 */
class ImportFormationController extends LecturerController
{
    protected const KIND = 'import';

    public function __construct(
        protected CsvReader $reader,
        protected ImportBuilder $builder,
        protected ImportReview $review,
        protected FormationSession $held,
        protected GroupFormation $formation,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    public function create(Request $request, Subject $subject): View|RedirectResponse
    {
        $subject = $this->ownedSubject($subject);
        $section = $this->sectionFor($subject, $request->query('section'));

        if ($section === null) {
            return redirect()->route('lecturer.formation', $subject)
                ->withErrors(['status' => 'Choose a section first: groups are imported one section at a time.']);
        }

        return view('lecturer.formation.import-upload', [
            'subject' => $subject,
            'section' => $section,
        ]);
    }

    /** A starting file with the right headings, for a lecturer building their form. */
    public function template(Request $request, Subject $subject): StreamedResponse
    {
        $this->ownedSubject($subject);

        $perStudent = $request->query('layout') === ImportBuilder::LAYOUT_STUDENT_ROWS;

        $rows = $perStudent
            ? [
                ['Timestamp', 'Matric number', 'Email', 'Group name'],
                ['2026-10-01 09:12:44', 'A21EC0001', 'student.one@student.example.edu', 'Team Alpha'],
                ['2026-10-01 09:15:02', 'A21EC0002', 'student.two@student.example.edu', 'Team Alpha'],
            ]
            : [
                ['Timestamp', 'Group name', 'Member 1 (matric number)', 'Member 2 (matric number)', 'Member 3 (matric number)', 'Member 4 (matric number)'],
                ['2026-10-01 09:12:44', 'Team Alpha', 'A21EC0001', 'A21EC0002', 'A21EC0003', 'A21EC0004'],
            ];

        $name = $perStudent ? 'group-form-one-row-per-student.csv' : 'group-form-one-row-per-group.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');

            // So Excel reads it as UTF-8 rather than guessing.
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);
        $section = $this->sectionFor($subject, $request->input('section'));

        if ($section === null) {
            return redirect()->route('lecturer.formation', $subject)
                ->withErrors(['status' => 'Choose a section first: groups are imported one section at a time.']);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:1024'],
            'layout' => ['required', Rule::in([ImportBuilder::LAYOUT_GROUP_ROWS, ImportBuilder::LAYOUT_STUDENT_ROWS])],
        ], [
            'file.required' => 'Choose the CSV file you exported from the form.',
            'file.extensions' => 'That is not a CSV file. In Google Forms: Responses, then the spreadsheet menu, then Download as CSV.',
            'file.max' => 'That file is over 1 MB, which is far more than a group form produces.',
        ]);

        try {
            $read = $this->reader->read($validated['file']->getRealPath());
        } catch (FormationException $e) {
            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        $token = $this->held->put(self::KIND, $subject->getKey(), [
            'stage' => 'columns',
            'section_id' => $section->getKey(),
            'layout' => $validated['layout'],
            'filename' => $this->safeName($validated['file']->getClientOriginalName()),
            'headers' => $read['headers'],
            'rows' => $read['rows'],
            'roles' => $this->builder->guessRoles($read['headers']),
            'draft' => null,
            'decisions' => [],
        ]);

        return redirect()->route('lecturer.formation.import.columns', [$subject, $token]);
    }

    /*
    |--------------------------------------------------------------------------
    | Columns
    |--------------------------------------------------------------------------
    */

    public function columns(Subject $subject, string $token): View|RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null) {
            return $this->expired($subject);
        }

        return view('lecturer.formation.import-columns', [
            'subject' => $subject,
            'section' => $this->heldSection($held, $subject),
            'token' => $token,
            'held' => $held,
            'sample' => array_slice($held['rows'], 0, 3),
        ]);
    }

    public function mapColumns(Request $request, Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null) {
            return $this->expired($subject);
        }

        $roles = array_map(
            fn ($r) => in_array($r, [ImportBuilder::ROLE_GROUP, ImportBuilder::ROLE_MEMBER], true) ? $r : ImportBuilder::ROLE_IGNORE,
            array_replace(array_fill(0, count($held['headers']), ImportBuilder::ROLE_IGNORE), (array) $request->input('roles', [])),
        );
        $roles = array_slice(array_values($roles), 0, count($held['headers']));

        $groupCols = count(array_filter($roles, fn ($r) => $r === ImportBuilder::ROLE_GROUP));
        $memberCols = count(array_filter($roles, fn ($r) => $r === ImportBuilder::ROLE_MEMBER));

        $held['roles'] = $roles;

        if ($groupCols !== 1 || $memberCols < 1) {
            $this->held->replace(self::KIND, $token, $held);

            return back()->withErrors(['roles' => $groupCols !== 1
                ? 'Mark exactly one column as the group name.'
                : 'Mark at least one column as holding members.']);
        }

        $draft = $this->builder->build($held['rows'], $roles, $held['layout']);

        if ($draft['groups'] === []) {
            $this->held->replace(self::KIND, $token, $held);

            return back()->withErrors(['roles' => 'No groups could be read with those columns. Check which one holds the group name.']);
        }

        $held['stage'] = 'review';
        $held['draft'] = $draft;
        // New columns mean new people with new keys, so earlier decisions no
        // longer refer to anything.
        $held['decisions'] = [];

        $this->held->replace(self::KIND, $token, $held);

        return redirect()->route('lecturer.formation.import.review', [$subject, $token]);
    }

    /*
    |--------------------------------------------------------------------------
    | Review
    |--------------------------------------------------------------------------
    */

    public function review(Subject $subject, string $token): View|RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null) {
            return $this->expired($subject);
        }

        if ($held['stage'] !== 'review') {
            return redirect()->route('lecturer.formation.import.columns', [$subject, $token]);
        }

        return view('lecturer.formation.import-review', [
            'subject' => $subject,
            'section' => $this->heldSection($held, $subject),
            'token' => $token,
            'held' => $held,
            'review' => $this->review->evaluate($held['draft'], $held['decisions'], $subject, $this->heldSection($held, $subject)),
            'decisions' => $held['decisions'],
            'notes' => $held['draft']['notes'],
            'limit' => ImportReview::HARD_LIMIT,
        ]);
    }

    /**
     * Saves the lecturer's decisions and shows the review again.
     *
     * Merges into what is already stored rather than replacing it, because the
     * controls come and go. A "raise this group's limit" box sits beside the
     * over-size problem and moves to the group list once the problem is gone; a
     * replace would have dropped the decision the moment its control vanished,
     * and the problem would have come straight back.
     *
     * Unticked checkboxes are not sent at all, so each one is accompanied by a
     * hidden marker saying "this box was on the page". Marker present and box
     * absent means switched off; marker absent means the box was not shown and
     * the stored decision stands.
     */
    public function decide(Request $request, Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null || $held['stage'] !== 'review') {
            return $this->expired($subject);
        }

        $held['decisions'] = $this->decisionsFrom($request, $held['draft'], $held['decisions']);

        $this->held->replace(self::KIND, $token, $held);

        return redirect()->route('lecturer.formation.import.review', [$subject, $token]);
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm
    |--------------------------------------------------------------------------
    */

    public function confirm(Request $request, Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $held = $this->held->get(self::KIND, $token, $subject->getKey());

        if ($held === null || $held['stage'] !== 'review') {
            return $this->expired($subject);
        }

        $mode = $request->input('mode') === 'clean' ? 'clean' : 'all';

        // Decisions made on this very submission count: the button is pressed
        // after editing the form, without a separate "apply" in between.
        if ($request->has('_decisions')) {
            $held['decisions'] = $this->decisionsFrom($request, $held['draft'], $held['decisions']);
            $this->held->replace(self::KIND, $token, $held);
        }

        $section = $this->heldSection($held, $subject);
        $review = $this->review->evaluate($held['draft'], $held['decisions'], $subject, $section);

        $allowed = $mode === 'all' ? $review['can_import_all'] : $review['counts']['clean'] > 0;

        if (! $allowed) {
            return redirect()->route('lecturer.formation.import.review', [$subject, $token])
                ->withErrors(['status' => $mode === 'all'
                    ? 'There are still problems to resolve, so nothing was created.'
                    : 'There is no clean group to import, so nothing was created.']);
        }

        $plans = $this->review->plans($review, $held['filename'], $mode === 'clean' && $review['counts']['problem'] > 0);

        try {
            $created = $this->formation->create($subject, auth()->user(), Group::FORMED_IMPORTED, $plans, $section);
        } catch (FormationException $e) {
            // Kept, unlike a balanced preview: the lecturer's decisions are the
            // work here, and the fix is usually small.
            return redirect()->route('lecturer.formation.import.review', [$subject, $token])
                ->withErrors(['status' => $e->getMessage()]);
        }

        $this->held->forget(self::KIND, $token);

        $students = array_sum(array_map(fn (Group $g) => $g->groupMembers()->count(), $created));
        $left = $this->review->candidates($subject, $section)->count();

        return redirect()->route('lecturer.formation', [$subject, 'section' => $section->getKey(), 'after' => 'import'])
            ->with('status', 'Imported '.count($created).' '.str('group')->plural(count($created))
                .' with '.$students.' '.str('student')->plural($students).'.'
                .($left > 0 ? " {$left} ".str('student')->plural($left).' still '.($left === 1 ? 'has' : 'have').' no group.' : ''));
    }

    public function discard(Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $this->held->forget(self::KIND, $token);

        return redirect()->route('lecturer.formation', $subject)
            ->with('status', 'Import discarded. Nothing was created.');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The stored decisions with this submission applied on top.
     *
     * Limited to things the draft actually contains. A key it has never heard
     * of is ignored rather than stored, so a hand-built request cannot plant
     * decisions about nothing.
     *
     * @param  array<string, array<string|int, mixed>>  $existing
     * @return array<string, array<string|int, mixed>>
     */
    protected function decisionsFrom(Request $request, array $draft, array $existing): array
    {
        $people = [];

        foreach ($draft['groups'] as $group) {
            foreach ($group['members'] as $person) {
                $people[$person['key']] = true;
            }
        }

        $groups = $draft['groups'];

        $decisions = $existing + ['remove' => [], 'assign' => [], 'keep' => [], 'rename' => [], 'raise' => [], 'skip' => []];

        // Checkboxes: only those whose marker came back are touched.
        $targets = ['remove' => $people, 'raise' => $groups, 'skip' => $groups];

        foreach ($targets as $kind => $valid) {
            $on = (array) $request->input($kind, []);

            foreach (array_keys((array) $request->input("seen.{$kind}", [])) as $key) {
                if (! isset($valid[$key])) {
                    continue;
                }

                if (! empty($on[$key])) {
                    $decisions[$kind][$key] = true;
                } else {
                    unset($decisions[$kind][$key]);
                }
            }
        }

        // Selects and text boxes always send a value, so presence is the marker.
        foreach ((array) $request->input('assign', []) as $pkey => $uid) {
            if (! isset($people[$pkey])) {
                continue;
            }

            if (ctype_digit((string) $uid) && (int) $uid > 0) {
                $decisions['assign'][$pkey] = (int) $uid;
            } else {
                unset($decisions['assign'][$pkey]);
            }
        }

        foreach ((array) $request->input('keep', []) as $uid => $choice) {
            if (! ctype_digit((string) $uid)) {
                continue;
            }

            $choice = (string) $choice;

            if ($choice === '') {
                unset($decisions['keep'][(int) $uid]);       // undecided
            } elseif ($choice === 'none') {
                $decisions['keep'][(int) $uid] = '';          // in neither group
            } elseif (isset($groups[$choice])) {
                $decisions['keep'][(int) $uid] = $choice;
            }
        }

        foreach ((array) $request->input('rename', []) as $gkey => $name) {
            if (! isset($groups[$gkey])) {
                continue;
            }

            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

            if ($name === '' || $name === $groups[$gkey]['name']) {
                unset($decisions['rename'][$gkey]);
            } else {
                $decisions['rename'][$gkey] = mb_substr($name, 0, 150);
            }
        }

        return $decisions;
    }

    protected function expired(Subject $subject): RedirectResponse
    {
        return redirect()->route('lecturer.formation', $subject)
            ->withErrors(['status' => 'That import has expired, so nothing was created. Upload the file again.']);
    }

    /** The section this import was started for (it never changes while the import is held). */
    protected function heldSection(array $held, Subject $subject): ?Section
    {
        return Section::query()->where('subject_id', $subject->getKey())->find($held['section_id'] ?? 0);
    }

    /** The file's name for the record, without anything that is not a name. */
    protected function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._\- ]+/u', '', $name) ?? '';

        return mb_substr($name, 0, 100) ?: 'upload.csv';
    }
}
