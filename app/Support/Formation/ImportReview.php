<?php

namespace App\Support\Formation;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Works out what an import would do, and what is wrong with it.
 *
 * Takes the parsed draft and the lecturer's decisions so far, and returns a
 * complete picture: each group, each person in it, every problem, and what is
 * left over. Run again after every decision, from scratch, against the database
 * as it is now. There is no stored verdict that could go stale: if a student is
 * placed by hand in another tab while the lecturer is reviewing, the next
 * evaluation sees it.
 *
 * The decisions (all optional, all keyed by the draft's own keys):
 *
 *   remove  person key  => true        drop this person from the group
 *   assign  person key  => user id     this person is that account
 *   keep    user id     => group key   a student listed twice stays only here
 *                          or ''       ... or in neither
 *   rename  group key   => string      a different name for the group
 *   raise   group key   => true        allow this group more members than the
 *                                      subject's usual limit
 *   skip    group key   => true        leave this group out entirely
 */
class ImportReview
{
    /** A group cannot hold more than this, whatever the lecturer allows. */
    public const HARD_LIMIT = 10;

    /**
     * @param  array{groups: array<string, array<string, mixed>>}  $draft
     * @param  array<string, array<string|int, mixed>>  $decisions
     * @return array<string, mixed>
     */
    public function evaluate(array $draft, array $decisions, Subject $subject): array
    {
        $remove = $decisions['remove'] ?? [];
        $assign = $decisions['assign'] ?? [];
        $keep = $decisions['keep'] ?? [];
        $rename = $decisions['rename'] ?? [];
        $raise = $decisions['raise'] ?? [];
        $skip = $decisions['skip'] ?? [];

        $users = $this->users($draft, $assign);
        $inSubject = $this->alreadyInSubject($subject);
        $existingNames = Group::query()->where('subject_id', $subject->getKey())->pluck('name')
            ->map(fn (string $n) => mb_strtolower(trim($n)))->all();
        $candidates = $this->candidates($subject);

        // 1. Resolve every person to an account and a state.
        $groups = [];

        foreach ($draft['groups'] as $gkey => $group) {
            $members = [];
            $seenInGroup = [];

            foreach ($group['members'] as $person) {
                $pkey = $person['key'];
                $uid = isset($assign[$pkey]) ? (int) $assign[$pkey] : $person['user_id'];
                $user = $uid ? $users->get($uid) : null;

                [$state, $reason] = match (true) {
                    ! empty($remove[$pkey]) => ['removed', 'Removed by you'],
                    $uid === null && $person['conflict'] !== null => ['conflict', 'The matric number and the email belong to different accounts'],
                    $user === null => ['not_found', 'No account has this matric number or email'],
                    $user->role !== 'student' => ['ineligible', 'This account belongs to a lecturer or administrator, not a student'],
                    ! $user->is_active => ['ineligible', 'This account has been deactivated'],
                    isset($inSubject[$user->id]) => ['ineligible', 'Already in '.$inSubject[$user->id].' for this subject'],
                    default => ['ok', null],
                };

                // The same account listed twice in one group is one member.
                if ($user !== null && in_array($state, ['ok', 'ineligible'], true)) {
                    if (isset($seenInGroup[$user->id])) {
                        [$state, $reason] = ['duplicate', 'Listed more than once in this group'];
                    }

                    $seenInGroup[$user->id] = true;
                }

                $members[] = $person + [
                    'state' => $state,
                    'reason' => $reason,
                    'user' => $user,
                    'matched_by_hand' => isset($assign[$pkey]),
                ];
            }

            $groups[$gkey] = [
                'key' => $gkey,
                'name' => $group['name'],
                'rows' => $group['rows'],
                'members' => $members,
                'skipped' => ! empty($skip[$gkey]),
            ];
        }

        // 2. A student in more than one group. Only the groups still in play
        //    count: a group the lecturer has skipped is not a claim on anyone.
        $appearances = [];

        foreach ($groups as $gkey => $group) {
            if ($group['skipped']) {
                continue;
            }

            foreach ($group['members'] as $person) {
                if ($person['state'] === 'ok') {
                    $appearances[$person['user']->id][] = $gkey;
                }
            }
        }

        $multi = [];
        // Every student listed in more than one group, decided or not. The screen
        // has to keep showing the control for one that is already decided: the
        // form resubmits every decision each time, so a control that vanished
        // would take its decision with it and the problem would come back.
        $multiAll = [];

        foreach ($appearances as $uid => $in) {
            if (count($in) < 2) {
                continue;
            }

            $chosen = array_key_exists($uid, $keep) ? $keep[$uid] : null;
            $multiAll[$uid] = ["user" => $users->get($uid), "groups" => $in, "chosen" => $chosen];

            if ($chosen !== null && ($chosen === '' || in_array($chosen, $in, true))) {
                // Decided. Everywhere except the chosen group, they are simply
                // not a member.
                foreach ($in as $gkey) {
                    if ($gkey === $chosen) {
                        continue;
                    }

                    foreach ($groups[$gkey]['members'] as $i => $person) {
                        if ($person['state'] === 'ok' && $person['user']->id === $uid) {
                            $groups[$gkey]['members'][$i]['state'] = 'moved';
                            $groups[$gkey]['members'][$i]['reason'] = $chosen === ''
                                ? 'Left out of every group by you'
                                : 'Kept in '.$groups[$chosen]['name'].' instead';
                        }
                    }
                }

                continue;
            }

            $multi[$uid] = [
                'user' => $users->get($uid),
                'groups' => $in,
                'chosen' => $chosen,
            ];
        }

        // 3. Per-group figures and problems.
        $effective = [];

        foreach ($groups as $gkey => $group) {
            $name = array_key_exists($gkey, $rename) && trim((string) $rename[$gkey]) !== ''
                ? trim((string) $rename[$gkey])
                : $group['name'];

            $groups[$gkey]['name'] = $name;
            $groups[$gkey]['original_name'] = $draft['groups'][$gkey]['name'];

            if (! $group['skipped']) {
                $effective[mb_strtolower($name)][] = $gkey;
            }
        }

        foreach ($groups as $gkey => $group) {
            $counted = array_filter($group['members'], fn ($p) => in_array($p['state'], ['ok', 'not_found', 'conflict', 'ineligible'], true));
            $count = count($counted);

            $base = $subject->group_size;
            $raised = ! empty($raise[$gkey]) && $count <= self::HARD_LIMIT;
            $limit = $raised ? max($base, $count) : $base;

            $key = mb_strtolower($group['name']);

            $nameProblem = match (true) {
                trim($group['name']) === '' => 'missing',
                mb_strlen($group['name']) > 100 => 'long',
                in_array($key, $existingNames, true) => 'taken',
                count($effective[$key] ?? []) > 1 => 'clash',
                default => null,
            };

            $problems = [
                'not_found' => array_keys(array_filter($group['members'], fn ($p) => in_array($p['state'], ['not_found', 'conflict'], true))),
                'ineligible' => array_keys(array_filter($group['members'], fn ($p) => $p['state'] === 'ineligible')),
                'multi' => array_values(array_filter(array_keys($multi), fn ($uid) => in_array($gkey, $multi[$uid]['groups'], true))),
                'over_size' => $count > $limit,
                'cannot_raise' => ! empty($raise[$gkey]) && $count > self::HARD_LIMIT,
                'name' => $nameProblem,
                'empty' => $count === 0,
            ];

            $hasProblem = $problems['not_found'] || $problems['ineligible'] || $problems['multi']
                || $problems['over_size'] || $problems['name'] !== null || $problems['empty'];

            $groups[$gkey]['count'] = $count;
            $groups[$gkey]['limit'] = $limit;
            $groups[$gkey]['raised'] = $raised && $limit > $base;
            $groups[$gkey]['problems'] = $problems;
            $groups[$gkey]['clean'] = ! $group['skipped'] && ! $hasProblem;
        }

        // 4. What the import would leave behind.
        $placed = [];

        foreach ($groups as $group) {
            if ($group['clean']) {
                foreach ($group['members'] as $person) {
                    if ($person['state'] === 'ok') {
                        $placed[$person['user']->id] = true;
                    }
                }
            }
        }

        $referenced = [];

        foreach ($draft['groups'] as $group) {
            foreach ($group['members'] as $person) {
                if ($person['user_id']) {
                    $referenced[$person['user_id']] = true;
                }

                foreach ($person['conflict'] ?? [] as $uid) {
                    $referenced[$uid] = true;
                }
            }
        }

        foreach ($assign as $uid) {
            $referenced[(int) $uid] = true;
        }

        $live = array_filter($groups, fn ($g) => ! $g['skipped']);
        $withProblems = array_filter($live, fn ($g) => ! $g['clean']);
        $clean = array_filter($live, fn ($g) => $g['clean']);

        return [
            'groups' => $groups,
            'multi' => $multi,
            'multi_all' => $multiAll,
            'candidates' => $candidates,
            // Eligible students the form never mentioned at all.
            'never_listed' => $candidates->reject(fn (User $u) => isset($referenced[$u->id]))->values(),
            // Everyone who ends up with no group once the clean ones are made.
            'unplaced' => $candidates->reject(fn (User $u) => isset($placed[$u->id]))->values(),
            'counts' => [
                'groups' => count($groups),
                'clean' => count($clean),
                'problem' => count($withProblems),
                'skipped' => count($groups) - count($live),
                'students' => array_sum(array_map(fn ($g) => $g['count'], $clean)),
            ],
            'can_import_all' => $clean !== [] && $withProblems === [],
            'can_import_clean' => $clean !== [] && $withProblems !== [],
        ];
    }

    /**
     * The plans GroupFormation needs, for the groups that are clean.
     *
     * @param  array<string, mixed>  $review
     * @return list<array{name: string, size: int, member_ids: list<int>, meta: array}>
     */
    public function plans(array $review, string $filename, bool $skippedBad): array
    {
        $plans = [];

        foreach ($review['groups'] as $gkey => $group) {
            if (! $group['clean']) {
                continue;
            }

            $ids = [];
            $byHand = 0;

            foreach ($group['members'] as $person) {
                if ($person['state'] === 'ok') {
                    $ids[] = $person['user']->id;
                    $byHand += $person['matched_by_hand'] ? 1 : 0;
                }
            }

            $plans[] = [
                'name' => $group['name'],
                'size' => max(2, $group['limit']),
                'member_ids' => $ids,
                'meta' => [
                    'source' => $filename,
                    'rows' => $group['rows'],
                    'matched_by_hand' => $byHand,
                    'limit_raised' => $group['raised'],
                    'problem_groups_skipped' => $skippedBad,
                ],
            ];
        }

        return $plans;
    }

    /**
     * Every account the draft or the decisions refer to.
     *
     * @return Collection<int, User>
     */
    protected function users(array $draft, array $assign): Collection
    {
        $ids = array_map('intval', array_values($assign));

        foreach ($draft['groups'] as $group) {
            foreach ($group['members'] as $person) {
                if ($person['user_id']) {
                    $ids[] = $person['user_id'];
                }
            }
        }

        return User::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');
    }

    /** @return array<int, string>  user id => the name of their group in this subject */
    protected function alreadyInSubject(Subject $subject): array
    {
        return GroupMember::query()
            ->whereHas('group', fn ($q) => $q->where('subject_id', $subject->getKey()))
            ->with('group:id,name')
            ->get()
            ->mapWithKeys(fn (GroupMember $m) => [$m->user_id => $m->group->name])
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    public function candidates(Subject $subject): Collection
    {
        return Pool::for($subject);
    }
}
