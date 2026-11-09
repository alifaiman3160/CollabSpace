<?php

namespace App\Support\Formation;

use App\Models\User;

/**
 * Turns the rows of a form export into a draft of groups and people.
 *
 * Two things happen here and neither of them touches the database to write:
 * the rows are grouped by the group name the students gave, and every person
 * named is matched to an account if one can be found.
 *
 * Matching is by matriculation number first and email second. Never by name.
 * Two students can share one, a name can be spelt a dozen ways, and a form
 * answer reading "Ahmad" has no business deciding which of three Ahmads gets
 * put in a group. A person who cannot be matched by number or email is left
 * unmatched, visibly, for the lecturer to resolve by hand. That costs a click.
 * Guessing wrong would put somebody in the wrong group without anyone noticing.
 */
class ImportBuilder
{
    /** One row per group, with several columns each holding a member. */
    public const LAYOUT_GROUP_ROWS = 'group_rows';

    /** One row per student, with a column saying which group they chose. */
    public const LAYOUT_STUDENT_ROWS = 'student_rows';

    public const ROLE_IGNORE = 'ignore';

    public const ROLE_GROUP = 'group';

    public const ROLE_MEMBER = 'member';

    /** Optional: marks the group's leader in a one-row-per-student file (Leader, Ketua: yes, y, 1, true, ya, leader, ketua). */
    public const ROLE_LEADER = 'leader';

    protected const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /**
     * A best guess at what each column is, from its heading.
     *
     * Only ever a starting point: the lecturer confirms it on the next screen,
     * because a form can word its questions any way at all and a wrong guess
     * that went unreviewed would build the whole import on the wrong column.
     *
     * @param  list<string>  $headers
     * @return list<string>  a role per column
     */
    public function guessRoles(array $headers): array
    {
        $roles = [];
        $haveGroup = false;
        $haveLeader = false;

        foreach ($headers as $header) {
            $h = mb_strtolower($header);

            $isNameOnly = preg_match('/\b(name|nama)\b/u', $h)
                && ! preg_match('/matric|matrik|e-?mail|mail|\bid\b/u', $h);

            if (preg_match('/timestamp|cap masa|\bdate\b|tarikh|score|skor/u', $h)) {
                $roles[] = self::ROLE_IGNORE;
            } elseif (! $haveLeader && preg_match('/^\s*(is\s+)?(group\s+|team\s+)?(leader|ketua)(\s+kumpulan)?\s*\??\s*$/u', $h)) {
                $roles[] = self::ROLE_LEADER;
                $haveLeader = true;
            } elseif (! $haveGroup
                && preg_match('/\b(group|team|kumpulan|pasukan)\b/u', $h)
                && ! preg_match('/member|ahli|leader|ketua|matric|matrik|e-?mail|mail|student|pelajar|\bid\b/u', $h)) {
                $roles[] = self::ROLE_GROUP;
                $haveGroup = true;
            } elseif (! $isNameOnly
                && preg_match('/matric|matrik|e-?mail|mail|member|ahli|student id|id pelajar/u', $h)) {
                $roles[] = self::ROLE_MEMBER;
            } else {
                $roles[] = self::ROLE_IGNORE;
            }
        }

        return $roles;
    }

    /**
     * Builds the draft.
     *
     * @param  list<array{n: int, cells: list<string>}>  $rows
     * @param  list<string>  $roles
     * @return array{groups: array<string, array<string, mixed>>, notes: list<string>}
     */
    public function build(array $rows, array $roles, string $layout): array
    {
        $groupCol = array_search(self::ROLE_GROUP, $roles, true);
        $memberCols = array_keys(array_filter($roles, fn ($r) => $r === self::ROLE_MEMBER));
        $leaderCol = array_search(self::ROLE_LEADER, $roles, true);

        $groups = [];
        $notes = [];
        $counter = 0;

        foreach ($rows as $row) {
            $n = $row['n'];
            $cells = $row['cells'];

            $name = $groupCol === false ? '' : $this->tidy($cells[$groupCol] ?? '');

            $people = $layout === self::LAYOUT_STUDENT_ROWS
                ? $this->studentRowPeople($cells, $memberCols, $n, $counter)
                : $this->groupRowPeople($cells, $memberCols, $n, $counter);

            // A leader column only means something when each row is one student.
            if ($layout === self::LAYOUT_STUDENT_ROWS && $leaderCol !== false && count($people) === 1) {
                $people[0]['leader'] = $this->truthy($cells[$leaderCol] ?? '');
            }

            if ($name === '' && $people === []) {
                continue;
            }

            if ($people === []) {
                $notes[] = "Row {$n} names the group \"{$name}\" but lists nobody, so it was ignored.";

                continue;
            }

            // Rows that give the same group name are the same group, matched
            // without regard to case or spacing. In a one-row-per-student form
            // that is the whole mechanism; in a one-row-per-group form it
            // absorbs a leader who submitted twice.
            $key = $name === '' ? 'row-'.$n : mb_strtolower($name);

            $groups[$key] ??= ['key' => 'g'.(count($groups) + 1), 'name' => $name, 'rows' => [], 'members' => []];
            $groups[$key]['rows'][] = $n;
            array_push($groups[$key]['members'], ...$people);
        }

        $groups = $this->match($groups);

        return ['groups' => array_column($groups, null, 'key'), 'notes' => $notes];
    }

    /** One person per member cell, and a cell may list several. */
    protected function groupRowPeople(array $cells, array $memberCols, int $n, int &$counter): array
    {
        $people = [];

        foreach ($memberCols as $col) {
            // Newlines and semicolons separate people; so do commas. A comma
            // can split "Ali, A21EC0011" into a name and a number, which leaves
            // the name as a visible unmatched entry the lecturer removes in one
            // click. Not splitting would instead hide the second and third
            // member of "A21EC0011, A21EC0012, A21EC0013" — silently losing
            // people is the failure to avoid, and this one is loud.
            foreach (preg_split('/[\r\n;,]+/u', (string) ($cells[$col] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $token) {
                $token = trim($token);

                if ($token !== '') {
                    $people[] = $this->person(++$counter, $token, [$token], $n);
                }
            }
        }

        return $people;
    }

    /** Every identifier cell in the row belongs to the one student. */
    protected function studentRowPeople(array $cells, array $memberCols, int $n, int &$counter): array
    {
        $filled = array_values(array_filter(
            array_map(fn ($c) => trim((string) ($cells[$c] ?? '')), $memberCols),
            fn ($c) => $c !== '',
        ));

        return $filled === [] ? [] : [$this->person(++$counter, implode(' / ', $filled), $filled, $n)];
    }

    protected function person(int $counter, string $raw, array $cells, int $row): array
    {
        return [
            'key' => 'p'.$counter,
            'raw' => $raw,
            'cells' => $cells,
            'row' => $row,
            'user_id' => null,
            'matched_by' => null,
            'conflict' => null,
            'leader' => false,
        ];
    }

    /** yes, y, 1, true, ya, leader, ketua (any case) mark the leader; empty or anything else does not. */
    protected function truthy(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['yes', 'y', '1', 'true', 'ya', 'leader', 'ketua'], true);
    }

    /**
     * Matches every person to an account, by matriculation number and then by
     * email.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    protected function match(array $groups): array
    {
        $matrics = [];
        $emails = [];

        foreach ($groups as $group) {
            foreach ($group['members'] as $person) {
                [$m, $e] = $this->identifiers($person['cells']);
                array_push($matrics, ...$m);
                array_push($emails, ...$e);
            }
        }

        $byMatric = $matrics === [] ? collect() : User::query()
            ->whereIn('matric_no', array_values(array_unique($matrics)))
            ->get()
            ->keyBy(fn (User $u) => mb_strtoupper((string) $u->matric_no));

        $byEmail = $emails === [] ? collect() : User::query()
            ->whereIn('email', array_values(array_unique($emails)))
            ->get()
            ->keyBy(fn (User $u) => mb_strtolower($u->email));

        foreach ($groups as $gkey => $group) {
            foreach ($group['members'] as $i => $person) {
                [$m, $e] = $this->identifiers($person['cells']);

                $viaMatric = collect($m)->map(fn ($id) => $byMatric->get($id))->filter()->first();
                $viaEmail = collect($e)->map(fn ($id) => $byEmail->get($id))->filter()->first();

                if ($viaMatric !== null) {
                    $groups[$gkey]['members'][$i]['user_id'] = $viaMatric->id;
                    $groups[$gkey]['members'][$i]['matched_by'] = 'matric';

                    // The number says one person and the email says another.
                    // Neither is trusted: the lecturer decides.
                    if ($viaEmail !== null && $viaEmail->id !== $viaMatric->id) {
                        $groups[$gkey]['members'][$i]['user_id'] = null;
                        $groups[$gkey]['members'][$i]['matched_by'] = null;
                        $groups[$gkey]['members'][$i]['conflict'] = [$viaMatric->id, $viaEmail->id];
                    }
                } elseif ($viaEmail !== null) {
                    $groups[$gkey]['members'][$i]['user_id'] = $viaEmail->id;
                    $groups[$gkey]['members'][$i]['matched_by'] = 'email';
                }
            }
        }

        return $groups;
    }

    /**
     * What a cell offers to be matched on.
     *
     * @param  list<string>  $cells
     * @return array{0: list<string>, 1: list<string>}  matric candidates, emails
     */
    protected function identifiers(array $cells): array
    {
        $matrics = [];
        $emails = [];

        foreach ($cells as $cell) {
            preg_match_all(self::EMAIL, $cell, $found);

            foreach ($found[0] as $email) {
                $emails[] = mb_strtolower($email);
            }

            // An email's local part is not a matric number, so it is taken out
            // before the rest is read.
            $rest = preg_replace(self::EMAIL, ' ', $cell);

            foreach (preg_split('/[^A-Za-z0-9]+/', (string) $rest, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (strlen($word) >= 4 && strlen($word) <= 20) {
                    $matrics[] = mb_strtoupper($word);
                }
            }

            // "A21-EC-0011" and "A21 EC 0011" are the same number typed the way
            // a form invites. Closing up the gaps recovers it.
            $compact = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $rest));

            if (strlen($compact) >= 5 && strlen($compact) <= 20) {
                $matrics[] = $compact;
            }
        }

        return [array_values(array_unique($matrics)), array_values(array_unique($emails))];
    }

    /** Trimmed, with runs of whitespace collapsed to one space. */
    protected function tidy(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
