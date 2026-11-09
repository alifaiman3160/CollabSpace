<x-layouts.app title="Review the import — CollabSpace"
               :heading="'Review the import'.($section ? ' — Section '.$section->name : '')"
               :subtitle="$subject->code.' — '.$held['filename']"
               :back="route('lecturer.formation.import.columns', [$subject, $token])"
               back-label="Change the columns">

    @php
        $groups = $review['groups'];
        $counts = $review['counts'];
        $candidates = $review['candidates'];
        $multi = $review['multi_all'];

        // Person rows, sorted into where their controls live. Each person has
        // exactly one home, so no control appears twice on the page.
        $unresolved = [];   // not found, ambiguous, ineligible, or matched by hand
        $overRows = [];     // otherwise-fine members of a group that is over its limit
        $removedRows = [];  // taken out by the lecturer, kept so they can be put back

        foreach ($groups as $gkey => $group) {
            if ($group['skipped']) {
                continue;
            }

            foreach ($group['members'] as $person) {
                $needsAttention = in_array($person['state'], ['not_found', 'conflict', 'ineligible'], true);

                if ($person['state'] === 'removed') {
                    $removedRows[] = [$gkey, $person];
                } elseif ($needsAttention || $person['matched_by_hand']) {
                    $unresolved[] = [$gkey, $person];
                } elseif ($person['state'] === 'ok' && $group['problems']['over_size']) {
                    $overRows[$gkey][] = $person;
                }
            }
        }

        $nameProblems = [
            'missing' => 'This group has no name.',
            'long' => 'The name is longer than 100 characters.',
            'taken' => 'A group with this name already exists in '.$subject->code.'.',
            'clash' => 'Another group in this file ends up with the same name.',
        ];

        $overGroups = array_filter($groups, fn ($g) => ! $g['skipped'] && $g['problems']['over_size']);
        $namedGroups = array_filter($groups, fn ($g) => ! $g['skipped'] && ($g['problems']['name'] !== null || $g['problems']['empty'] || $g['problems']['multi_leader']));
        $skippedGroups = array_filter($groups, fn ($g) => $g['skipped']);
        $problemGroups = array_filter($groups, fn ($g) => ! $g['skipped'] && ! $g['clean']);
        $cleanGroups = array_filter($groups, fn ($g) => $g['clean']);

        $field = 'h-11 w-full rounded-[14px] bg-surface border border-surface-line px-3.5 text-[13px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10';
        $box = 'size-4 shrink-0 rounded border-surface-strong text-ink focus:ring-ink/20';
    @endphp

    <div class="flex flex-col gap-[18px]">

        <x-flash />

        <div class="flex items-center gap-3 rounded-card border border-periwinkle-deep bg-periwinkle-soft px-5 py-4">
            <x-icon name="eye" size="18" class="shrink-0 text-periwinkle-link" />
            <p class="min-w-0 flex-1 text-body font-bold text-ink">
                Nothing has been created.
                <span class="font-medium text-ink-600">
                    @if ($review['can_import_all'])
                        Everything below reads cleanly. Check it, then create the groups.
                    @else
                        Sort out each problem below, or leave the problem groups out. Choices are kept as you go.
                    @endif
                </span>
            </p>
        </div>

        <section class="flex flex-wrap gap-3.5" aria-label="Summary">
            <x-stat-card size="sm" tone="periwinkle" icon="folder" icon-tone="neutral"
                         label="Groups in the file" :value="$counts['groups']"
                         :meta="count($held['rows']).' '.Str::plural('response', count($held['rows']))" />

            <x-stat-card size="sm" icon="check" icon-tone="success"
                         label="Ready" :value="$counts['clean']"
                         :meta="$counts['students'].' '.Str::plural('student', $counts['students'])" />

            <x-stat-card size="sm" icon="alert" icon-tone="danger"
                         label="Need attention" :value="$counts['problem']"
                         :meta="$counts['problem'] ? 'Resolve or leave out' : 'None'" />

            <x-stat-card size="sm" icon="users" icon-tone="neutral"
                         label="Left without a group" :value="$review['unplaced']->count()"
                         meta="After the ready groups are made" />
        </section>

        @foreach ($notes as $note)
            <div class="flex items-start gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-3">
                <x-icon name="alert" size="16" class="mt-0.5 shrink-0 text-ink-300" />
                <p class="text-meta text-ink-400">{{ $note }}</p>
            </div>
        @endforeach

        <form method="POST" action="{{ route('lecturer.formation.import.decide', [$subject, $token]) }}"
              class="flex flex-col gap-[18px]">
            @csrf
            <input type="hidden" name="_decisions" value="1">

            {{-- 1. Groups that read cleanly --}}
            <section class="flex flex-col gap-3" aria-labelledby="clean-heading">
                <h2 id="clean-heading" class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                    Groups that read cleanly
                    <x-badge tone="success">{{ count($cleanGroups) }}</x-badge>
                </h2>

                @if ($cleanGroups === [])
                    <p class="rounded-card border border-surface-line bg-surface-soft px-5 py-4 text-meta text-ink-400">
                        No group is ready yet. Fix the problems below and they will appear here.
                    </p>
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($cleanGroups as $gkey => $group)
                            <article class="flex flex-col gap-2.5 rounded-panel border border-surface-line bg-surface p-4 shadow-subtle">
                                <header class="flex items-start justify-between gap-2">
                                    <div class="flex min-w-0 flex-col">
                                        <h3 class="truncate text-nav font-extrabold">{{ $group['name'] }}</h3>
                                        <span class="text-label text-ink-400">
                                            {{ $group['count'] }} / {{ $group['limit'] }}
                                            @if (count($group['rows']) > 0)
                                                · {{ Str::plural('row', count($group['rows'])) }} {{ implode(', ', $group['rows']) }}
                                            @endif
                                        </span>
                                    </div>
                                    @if ($group['raised'])
                                        <x-badge tone="info">Limit raised</x-badge>
                                    @endif
                                </header>

                                <ul class="flex flex-col gap-1">
                                    @foreach ($group['members'] as $person)
                                        @if ($person['state'] === 'ok')
                                            <li class="flex items-center gap-2 text-meta">
                                                <span class="flex min-w-0 flex-1 items-center gap-1.5"><span class="truncate font-semibold">{{ $person['user']->name }}</span>@if (($group['leader_preview_id'] ?? null) === $person['user']->id)<x-leader-badge />@endif</span>
                                                <span class="shrink-0 text-label text-ink-400">
                                                    {{ $person['matched_by_hand'] ? 'matched by you' : 'by '.$person['matched_by'] }}
                                                </span>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- 2. Named but not found, or found and not placeable --}}
            <section class="flex flex-col gap-3" aria-labelledby="notfound-heading">
                <h2 id="notfound-heading" class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                    Students not found, or who cannot be placed
                    <x-badge :tone="count(array_filter($unresolved, fn ($r) => $r[1]['state'] !== 'ok')) ? 'danger' : 'neutral'">
                        {{ count($unresolved) }}
                    </x-badge>
                </h2>

                @if ($unresolved === [])
                    <p class="rounded-card border border-surface-line bg-surface-soft px-5 py-4 text-meta text-ink-400">
                        Everyone named in the file was matched to an account.
                    </p>
                @else
                    <p class="text-meta leading-[1.5] text-ink-400">
                        These could not be matched by matric number or email, or matched an account that cannot join.
                        Pick the right student from the list, or remove the entry.
                    </p>

                    <ul class="flex flex-col gap-2.5">
                        @foreach ($unresolved as [$gkey, $person])
                            @php
                                $looksLikeName = ! preg_match('/\d|@/', $person['raw']);
                                $chosen = $decisions['assign'][$person['key']] ?? null;
                            @endphp
                            <li class="flex flex-wrap items-start gap-4 rounded-card border bg-surface p-4
                                       {{ $person['state'] === 'ok' ? 'border-surface-line' : 'border-danger-line' }}">
                                <div class="flex min-w-[220px] flex-1 flex-col gap-1">
                                    <span class="text-nav font-bold">“{{ $person['raw'] }}”</span>
                                    <span class="text-label text-ink-400">
                                        {{ $groups[$gkey]['name'] }} · row {{ $person['row'] }}
                                    </span>
                                    @if ($person['state'] === 'ok')
                                        <span class="text-meta font-semibold text-success-text">
                                            Matched by you to {{ $person['user']->name }}
                                        </span>
                                    @else
                                        <span class="text-meta font-semibold text-danger-text">{{ $person['reason'] }}</span>
                                        @if ($looksLikeName && $person['state'] === 'not_found')
                                            <span class="text-label text-ink-400">
                                                That looks like a name. Names are never used to match students: choose the account yourself.
                                            </span>
                                        @endif
                                    @endif
                                </div>

                                <div class="flex min-w-[240px] flex-1 flex-col gap-2">
                                    <select name="assign[{{ $person['key'] }}]" class="{{ $field }}"
                                            aria-label="Which student is “{{ $person['raw'] }}”?">
                                        <option value="">Choose a student…</option>
                                        @foreach ($candidates as $candidate)
                                            <option value="{{ $candidate->id }}" @selected($chosen === $candidate->id)>
                                                {{ $candidate->name }}{{ $candidate->matric_no ? ' — '.$candidate->matric_no : '' }}
                                            </option>
                                        @endforeach
                                    </select>

                                    <label class="flex w-fit cursor-pointer items-center gap-2 text-meta font-semibold text-ink-600">
                                        <input type="hidden" name="seen[remove][{{ $person['key'] }}]" value="1">
                                        <input type="checkbox" name="remove[{{ $person['key'] }}]" value="1" class="{{ $box }}"
                                               @checked(! empty($decisions['remove'][$person['key']]))>
                                        Remove from the group
                                    </label>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- 3. In more than one group --}}
            <section class="flex flex-col gap-3" aria-labelledby="multi-heading">
                <h2 id="multi-heading" class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                    Students in more than one group
                    <x-badge :tone="count($review['multi']) ? 'danger' : 'neutral'">{{ count($multi) }}</x-badge>
                </h2>

                @if ($multi === [])
                    <p class="rounded-card border border-surface-line bg-surface-soft px-5 py-4 text-meta text-ink-400">
                        Nobody is listed in more than one group.
                    </p>
                @else
                    <p class="text-meta leading-[1.5] text-ink-400">
                        A student can only be in one group for {{ $subject->code }}. Choose where each one stays.
                    </p>

                    <ul class="flex flex-col gap-2.5">
                        @foreach ($multi as $uid => $entry)
                            @php
                                $decided = array_key_exists($uid, $decisions['keep'] ?? []);
                                $keepValue = $decided ? ($decisions['keep'][$uid] === '' ? 'none' : $decisions['keep'][$uid]) : '';
                            @endphp
                            <li class="flex flex-wrap items-center gap-4 rounded-card border bg-surface p-4
                                       {{ $decided ? 'border-surface-line' : 'border-danger-line' }}">
                                <div class="flex min-w-[220px] flex-1 flex-col gap-1">
                                    <span class="text-nav font-bold">{{ $entry['user']->name }}</span>
                                    <span class="text-label text-ink-400">
                                        Listed in {{ collect($entry['groups'])->map(fn ($k) => $groups[$k]['name'])->implode(' and ') }}
                                    </span>
                                </div>

                                <select name="keep[{{ $uid }}]" class="{{ $field }} sm:max-w-[280px]"
                                        aria-label="Where {{ $entry['user']->name }} stays">
                                    <option value="" @selected($keepValue === '')>Choose where they stay…</option>
                                    @foreach ($entry['groups'] as $k)
                                        <option value="{{ $k }}" @selected($keepValue === $k)>Stay in {{ $groups[$k]['name'] }}</option>
                                    @endforeach
                                    <option value="none" @selected($keepValue === 'none')>Leave out of both</option>
                                </select>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- 4. Over the size limit --}}
            <section class="flex flex-col gap-3" aria-labelledby="over-heading">
                <h2 id="over-heading" class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                    Groups over the size limit
                    <x-badge :tone="count($overGroups) ? 'danger' : 'neutral'">{{ count($overGroups) }}</x-badge>
                </h2>

                @if ($overGroups === [])
                    <p class="rounded-card border border-surface-line bg-surface-soft px-5 py-4 text-meta text-ink-400">
                        No group is over the limit of {{ $subject->group_size }}.
                    </p>
                @else
                    <p class="text-meta leading-[1.5] text-ink-400">
                        {{ $subject->code }} allows {{ $subject->group_size }} per group. Remove members, or allow this
                        group to be larger. A group can never exceed {{ $limit }}.
                    </p>

                    <div class="flex flex-col gap-3">
                        @foreach ($overGroups as $gkey => $group)
                            <article class="flex flex-col gap-3 rounded-card border border-danger-line bg-surface p-4">
                                <header class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-nav font-extrabold">{{ $group['name'] }}</h3>
                                    <x-badge tone="danger">{{ $group['count'] }} members, limit {{ $group['limit'] }}</x-badge>
                                </header>

                                @if ($group['problems']['cannot_raise'])
                                    <p class="text-meta font-semibold text-danger-text">
                                        This group has more than {{ $limit }} members, which no group can have. Remove some.
                                    </p>
                                @endif

                                <ul class="flex flex-col gap-1.5">
                                    @foreach ($overRows[$gkey] ?? [] as $person)
                                        <li class="flex items-center gap-3 rounded-[12px] bg-surface-soft px-3 py-2">
                                            <span class="min-w-0 flex-1 truncate text-meta font-bold">{{ $person['user']->name }}</span>
                                            <label class="flex shrink-0 cursor-pointer items-center gap-2 text-meta font-semibold text-ink-600">
                                                <input type="hidden" name="seen[remove][{{ $person['key'] }}]" value="1">
                                                <input type="checkbox" name="remove[{{ $person['key'] }}]" value="1" class="{{ $box }}"
                                                       @checked(! empty($decisions['remove'][$person['key']]))>
                                                Remove
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($group['count'] <= $limit)
                                    <label class="flex w-fit cursor-pointer items-center gap-2 text-meta font-semibold text-ink-600">
                                        <input type="hidden" name="seen[raise][{{ $gkey }}]" value="1">
                                        <input type="checkbox" name="raise[{{ $gkey }}]" value="1" class="{{ $box }}"
                                               @checked(! empty($decisions['raise'][$gkey]))>
                                        Allow this group {{ $group['count'] }} members instead
                                    </label>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- 5. Names and empty groups --}}
            @if ($namedGroups !== [])
                <section class="flex flex-col gap-3" aria-labelledby="name-heading">
                    <h2 id="name-heading" class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                        Group names and empty groups
                        <x-badge tone="danger">{{ count($namedGroups) }}</x-badge>
                    </h2>

                    <div class="flex flex-col gap-3">
                        @foreach ($namedGroups as $gkey => $group)
                            <article class="flex flex-wrap items-start gap-4 rounded-card border border-danger-line bg-surface p-4">
                                <div class="flex min-w-[220px] flex-1 flex-col gap-1">
                                    <span class="text-nav font-bold">{{ $group['original_name'] !== '' ? $group['original_name'] : 'Unnamed group' }}</span>
                                    <span class="text-label text-ink-400">Row {{ implode(', ', $group['rows']) }}</span>
                                    @if ($group['problems']['name'])
                                        <span class="text-meta font-semibold text-danger-text">{{ $nameProblems[$group['problems']['name']] }}</span>
                                    @endif
                                    @if ($group['problems']['multi_leader'])
                                        <div class="flex w-full flex-col gap-1.5" data-multi-leader>
                                            <span class="text-meta font-semibold text-danger-text">More than one student is marked as the leader. Choose who leads this group:</span>
                                            @foreach ($group['leader_marked'] as $cand)
                                                <label class="flex items-center gap-2 text-meta font-semibold text-ink-600">
                                                    <input type="radio" name="leader[{{ $gkey }}]" value="{{ $cand['key'] }}" @checked(($decisions['leader'][$gkey] ?? null) === $cand['key']) class="size-4 border-surface-strong text-ink focus:ring-ink/20">
                                                    {{ $cand['user']->name }}
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($group['problems']['empty'])
                                        <span class="text-meta font-semibold text-danger-text">
                                            Nobody is left in this group. Put people back, or leave the group out below.
                                        </span>
                                    @endif
                                </div>

                                @if ($group['problems']['name'])
                                    <div class="flex min-w-[240px] flex-1 flex-col gap-1.5">
                                        <label for="rename-{{ $gkey }}" class="text-meta font-bold text-ink-700">Give it a different name</label>
                                        <input id="rename-{{ $gkey }}" type="text" name="rename[{{ $gkey }}]" maxlength="150"
                                               value="{{ $decisions['rename'][$gkey] ?? '' }}"
                                               placeholder="{{ $group['original_name'] }}" class="{{ $field }}">
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Taken out by you, kept so they can be put back --}}
            @if ($removedRows !== [])
                <section class="flex flex-col gap-3" aria-labelledby="removed-heading">
                    <h2 id="removed-heading" class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                        Removed by you
                        <x-badge tone="neutral">{{ count($removedRows) }}</x-badge>
                    </h2>

                    <ul class="flex flex-col gap-1.5">
                        @foreach ($removedRows as [$gkey, $person])
                            <li class="flex items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-4 py-2.5">
                                <span class="min-w-0 flex-1 truncate text-meta text-ink-400">
                                    “{{ $person['raw'] }}” from {{ $groups[$gkey]['name'] }}
                                </span>
                                <label class="flex shrink-0 cursor-pointer items-center gap-2 text-meta font-semibold text-ink-600">
                                    <input type="hidden" name="seen[remove][{{ $person['key'] }}]" value="1">
                                    <input type="checkbox" name="remove[{{ $person['key'] }}]" value="1" class="{{ $box }}" checked>
                                    Removed
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-label text-ink-400">Untick one and apply to put them back.</p>
                </section>
            @endif

            {{-- 6. Never mentioned --}}
            <section class="flex flex-col gap-3" aria-labelledby="never-heading">
                <h2 id="never-heading" class="flex items-center gap-2 text-h3 font-extrabold tracking-title">
                    Students who never appear
                    <x-badge tone="neutral">{{ $review['never_listed']->count() }}</x-badge>
                </h2>

                @if ($review['never_listed']->isEmpty())
                    <p class="rounded-card border border-surface-line bg-surface-soft px-5 py-4 text-meta text-ink-400">
                        Every student without a group appears in the file.
                    </p>
                @else
                    <p class="text-meta leading-[1.5] text-ink-400">
                        Active students with no group in {{ $subject->code }} who the form never mentioned. They are not
                        affected by this import. Once it is done you can place them with balanced allocation.
                    </p>

                    <ul class="grid max-h-[260px] gap-1.5 overflow-y-auto rounded-card border border-surface-line bg-surface-soft p-2.5 sm:grid-cols-2">
                        @foreach ($review['never_listed'] as $student)
                            <li class="flex items-center gap-3 rounded-[12px] bg-surface px-3 py-2">
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-meta font-bold">{{ $student->name }}</span>
                                    <span class="truncate text-label text-ink-400">{{ $student->matric_no ?? $student->email }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Every group: the controls that always apply --}}
            <section class="flex flex-col gap-3" aria-labelledby="all-heading">
                <h2 id="all-heading" class="text-h3 font-extrabold tracking-title">Every group in the file</h2>

                <ul class="flex flex-col gap-2">
                    @foreach ($groups as $gkey => $group)
                        <li id="group-{{ $gkey }}"
                            class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface px-4 py-3">
                            <div class="flex min-w-[200px] flex-1 flex-col gap-1.5">
                                @if ($group['problems']['name'] === null)
                                    <input type="text" name="rename[{{ $gkey }}]" maxlength="150"
                                           value="{{ $group['name'] }}"
                                           aria-label="Name of the group {{ $group['name'] }}"
                                           class="{{ $field }} max-w-[320px] font-bold">
                                @else
                                    <span class="text-nav font-bold">{{ $group['name'] !== '' ? $group['name'] : 'Unnamed group' }}</span>
                                @endif
                                <span class="text-label text-ink-400">
                                    {{ $group['count'] }} / {{ $group['limit'] }} · {{ Str::plural('row', count($group['rows'])) }} {{ implode(', ', $group['rows']) }}
                                </span>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                @if ($group['skipped'])
                                    <x-badge tone="neutral">Left out</x-badge>
                                @elseif ($group['clean'])
                                    <x-badge tone="success">Ready</x-badge>
                                @else
                                    <x-badge tone="danger">Needs attention</x-badge>
                                @endif

                                @unless ($group['problems']['over_size'])
                                    <label class="flex cursor-pointer items-center gap-2 text-meta font-semibold text-ink-600">
                                        <input type="hidden" name="seen[raise][{{ $gkey }}]" value="1">
                                        <input type="checkbox" name="raise[{{ $gkey }}]" value="1" class="{{ $box }}"
                                               @checked(! empty($decisions['raise'][$gkey]))>
                                        Allow a larger group
                                    </label>
                                @endunless

                                <label class="flex cursor-pointer items-center gap-2 text-meta font-semibold text-ink-600">
                                    <input type="hidden" name="seen[skip][{{ $gkey }}]" value="1">
                                    <input type="checkbox" name="skip[{{ $gkey }}]" value="1" class="{{ $box }}"
                                           @checked(! empty($decisions['skip'][$gkey]))>
                                    Leave this group out
                                </label>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- What to do --}}
            <section class="flex flex-col gap-4 rounded-panel border border-surface-strong bg-surface p-5 shadow-subtle"
                     aria-labelledby="finish-heading">
                <h2 id="finish-heading" class="text-h3 font-extrabold tracking-title">Finish</h2>

                @if ($review['can_import_all'])
                    <p class="text-body text-ink-600">
                        {{ $counts['clean'] }} {{ Str::plural('group', $counts['clean']) }} with {{ $counts['students'] }}
                        {{ Str::plural('student', $counts['students']) }} will be created, and each student will be notified.
                        @if ($review['unplaced']->isNotEmpty())
                            {{ $review['unplaced']->count() }} {{ Str::plural('student', $review['unplaced']->count()) }} will still have
                            no group, and you can place them next.
                        @endif
                    </p>
                @elseif ($counts['groups'] === count($skippedGroups))
                    <p class="text-body text-ink-600">Every group is left out, so there is nothing to create.</p>
                @else
                    <p class="text-body text-ink-600">
                        {{ $counts['problem'] }} {{ Str::plural('group', $counts['problem']) }} still
                        {{ $counts['problem'] === 1 ? 'has' : 'have' }} problems, so the full import is not available yet.
                    </p>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <x-button variant="secondary" type="submit">Apply my choices</x-button>

                    @if ($review['can_import_all'])
                        <x-button type="submit" formaction="{{ route('lecturer.formation.import.confirm', [$subject, $token]) }}"
                                  name="mode" value="all">
                            <x-icon name="check" size="16" />
                            Create {{ $counts['clean'] }} {{ Str::plural('group', $counts['clean']) }}
                        </x-button>
                    @else
                        <x-button type="button" disabled>Resolve the problems to import everything</x-button>
                    @endif
                </div>

                @if ($review['can_import_clean'])
                    <div class="flex flex-col gap-2.5 rounded-card border border-surface-line bg-surface-soft p-4">
                        <p class="text-meta font-bold text-ink-700">Or skip the problem groups</p>
                        <p class="text-meta leading-[1.5] text-ink-400">
                            Creates only the {{ $counts['clean'] }} ready {{ Str::plural('group', $counts['clean']) }}
                            ({{ $counts['students'] }} {{ Str::plural('student', $counts['students']) }}). These are left
                            out entirely, and their students go back to having no group:
                        </p>
                        <ul class="flex flex-wrap gap-1.5">
                            @foreach ($problemGroups as $group)
                                <li><x-badge tone="danger">{{ $group['name'] !== '' ? $group['name'] : 'Unnamed group' }}</x-badge></li>
                            @endforeach
                        </ul>
                        <div>
                            <x-button variant="secondary" type="submit"
                                      formaction="{{ route('lecturer.formation.import.confirm', [$subject, $token]) }}"
                                      name="mode" value="clean">
                                Skip {{ count($problemGroups) }} and create {{ $counts['clean'] }}
                            </x-button>
                        </div>
                    </div>
                @endif
            </section>
        </form>

        <form method="POST" action="{{ route('lecturer.formation.import.discard', [$subject, $token]) }}">
            @csrf
            @method('DELETE')
            <x-button variant="secondary" type="submit" class="!text-danger-text">Discard this import</x-button>
        </form>
    </div>

</x-layouts.app>
