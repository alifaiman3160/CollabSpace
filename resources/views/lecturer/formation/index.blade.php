<x-layouts.app title="Auto-assign students — CollabSpace"
               :heading="'Auto-assign students'.($section ? ' — Section '.$section->name : '')"
               :subtitle="$subject->code.' — '.$subject->name"
               :back="route('lecturer.groups', ['subject' => $subject->id])"
               :back-label="$subject->code.' groups'">

    @php
        $count = $pool->count();
        $canBalance = $count >= 2;
        $checked = collect(old('students', $pool->pluck('id')->all()))->map(fn ($id) => (int) $id)->all();

        // The form is hidden until the mode is chosen, but a refused submission
        // must come back with it open.
        $openBalanced = $errors->hasAny(['students', 'groups', 'prefix']) || old('groups') !== null;

        $standing = $existingGroups.' existing '.Str::plural('group', $existingGroups).' left unchanged'
            .($emptyGroups > 0 ? ' ('.$emptyGroups.' empty)' : '')
            .' · up to '.$subject->group_size.' members per group';
    @endphp

    <div class="flex flex-col gap-[18px]" x-data="{ mode: {{ $openBalanced ? "'balanced'" : 'null' }} }">

        <x-flash />

        {{-- Groups are formed one section at a time: pick which section first. --}}
        @if ($sections->isEmpty())
            <x-empty-state icon="users" title="No students are enrolled in this subject yet"
                           body="The administrator enrols students in a subject, with a section, from User accounts. Once that is done, you can form groups here." />
        @else
            <div class="flex flex-wrap items-center gap-3" data-section-picker>
                <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Section</span>
                <div class="flex flex-wrap rounded-pill bg-surface-muted p-[3px]" role="group" aria-label="Section">
                    @foreach ($sections as $option)
                        <a href="{{ route('lecturer.formation', [$subject, 'section' => $option->id]) }}"
                           @if ($section?->is($option)) aria-current="page" @endif
                           class="flex h-8 items-center gap-1.5 rounded-pill px-[15px] text-meta font-bold interactive {{ $section?->is($option) ? 'bg-ink text-white' : 'text-ink-600 hover:text-ink' }}">
                            Section {{ $option->name }}
                            <span class="text-label font-semibold opacity-70">{{ $option->enrolments_count }}</span>
                        </a>
                    @endforeach
                </div>
                <span class="text-meta text-ink-400">Students are only taken from this section.</span>
            </div>

            @if ($section === null)
                <p class="text-body text-ink-400" data-choose-section>Choose a section to see who still needs a group. Students of other sections are never mixed in.</p>
            @endif
        @endif

        @if ($section)
        <section class="flex flex-wrap gap-3.5" aria-label="Where this subject stands">
            <x-stat-card size="sm" tone="periwinkle" icon="users" icon-tone="neutral"
                         label="Without a group" :value="$count"
                         :meta="$count === 0 ? 'Everyone is placed' : 'Active students with no group here'" />

            <x-stat-card size="sm" icon="graduation" icon-tone="info"
                         label="With a CGPA" :value="$withCgpa.' / '.$count"
                         :meta="$count === 0 ? '—' : ($withCgpa === $count ? 'Every one recorded' : ($count - $withCgpa).' not recorded')" />
        </section>

        <p class="-mt-1 text-label text-ink-400">{{ $standing }}</p>

        @if ($afterImport && $count > 0)
            <div class="flex items-center gap-3 rounded-card border border-periwinkle-deep bg-periwinkle-soft px-5 py-3.5">
                <x-icon name="users" size="18" class="shrink-0 text-periwinkle-link" />
                <p class="min-w-0 flex-1 text-body font-semibold text-ink">
                    {{ $count }} {{ Str::plural('student', $count) }} {{ $count === 1 ? 'was' : 'were' }} not in any group you imported.
                    Place them with balanced allocation below.
                </p>
            </div>
        @endif

        {{-- Two equal choices. Each says what it does in one line; the working
             form for a mode opens only once it is chosen. --}}
        <section class="grid gap-3.5 md:grid-cols-2" aria-label="How to assign">
            <div class="flex flex-col gap-4 rounded-panel border bg-surface p-6 shadow-subtle"
                 :class="mode === 'balanced' ? 'border-ink' : 'border-surface-line'">
                <div class="flex items-start gap-3.5">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-[14px] bg-periwinkle-soft text-periwinkle-link">
                        <x-icon name="chart" size="20" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <h2 class="text-h3 font-bold tracking-title">Balance groups by CGPA</h2>
                        <p class="text-body leading-[1.5] text-ink-400">
                            @if ($canBalance)
                                CollabSpace spreads stronger and weaker students evenly across the groups.
                            @else
                                Fewer than two students need a group, so there is nothing to balance.
                            @endif
                        </p>
                    </div>
                </div>

                <div class="mt-auto">
                    @if ($canBalance)
                        <x-button variant="secondary" x-on:click="mode = 'balanced'"
                                  x-bind:aria-expanded="mode === 'balanced'" aria-controls="balanced-form">
                            Balance by CGPA
                        </x-button>
                    @else
                        <x-button variant="secondary" disabled>Balance by CGPA</x-button>
                    @endif
                </div>
            </div>

            <div class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">
                <div class="flex items-start gap-3.5">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-[14px] bg-success-bg text-success-text">
                        <x-icon name="file" size="20" />
                    </span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <h2 class="text-h3 font-bold tracking-title">Import the groups students chose</h2>
                        <p class="text-body leading-[1.5] text-ink-400">
                            Create the groups students picked themselves in a Google Form, from its CSV.
                        </p>
                    </div>
                </div>

                <div class="mt-auto">
                    <x-button variant="secondary" :href="route('lecturer.formation.import', [$subject, 'section' => $section?->id])" icon="plus">
                        Import from CSV
                    </x-button>
                </div>
            </div>
        </section>

        @if ($canBalance)
            <section id="balanced-form" x-show="mode === 'balanced'" x-cloak
                     x-transition:enter="transition ease-motion duration-motion-slow"
                     x-transition:enter-start="opacity-0 scale-[.96]"
                     x-transition:enter-end="opacity-100 scale-100"
                     class="flex flex-col gap-5 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">
                <h2 class="text-h3 font-bold tracking-title">Balance by CGPA</h2>

        <form method="POST" action="{{ route('lecturer.formation.balanced.preview', $subject) }}"
              class="flex flex-col gap-5"
              x-data="{
                  limit: {{ (int) $subject->group_size }},
                  picked: {{ count($checked) }},
                  groups: {{ (int) old('groups', $bounds['min']) }},
                  get min() { return this.picked < 2 ? 0 : Math.max(1, Math.ceil(this.picked / this.limit)) },
                  get max() { return this.picked < 2 ? 0 : Math.max(this.min, Math.floor(this.picked / 2)) },
                  recount() {
                      this.picked = this.$root.querySelectorAll('input[name=\'students[]\']:checked').length
                      if (this.picked >= 2) { this.groups = Math.min(Math.max(this.groups, this.min), this.max) }
                  },
                  setAll(on) {
                      this.$root.querySelectorAll('input[name=\'students[]\']').forEach(box => box.checked = on)
                      this.recount()
                  },
              }">
            @csrf
            <input type="hidden" name="section" value="{{ $section?->id }}">

            <div class="flex flex-col gap-2.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <x-input-label value="Who to place" />
                    <span class="flex items-center gap-2 text-meta font-semibold">
                        <button type="button" class="interactive text-periwinkle-link hover:underline" x-on:click="setAll(true)">All</button>
                        <span class="text-ink-200" aria-hidden="true">·</span>
                        <button type="button" class="interactive text-periwinkle-link hover:underline" x-on:click="setAll(false)">None</button>
                    </span>
                </div>

                <p class="text-meta text-ink-400">Section {{ $section?->name }} only. Untick anyone you do not want to place now.</p>

                <ul class="grid max-h-[320px] gap-1.5 overflow-y-auto rounded-card border border-surface-line bg-surface-soft p-2.5 sm:grid-cols-2"
                    aria-label="Students without a group">
                    @foreach ($pool as $student)
                        <li>
                            <label class="flex cursor-pointer items-center gap-3 rounded-[12px] bg-surface px-3 py-2.5 transition-colors hover:bg-surface-hover">
                                <input type="checkbox" name="students[]" value="{{ $student->id }}"
                                       @checked(in_array($student->id, $checked, true))
                                       x-on:change="recount()"
                                       class="size-4 shrink-0 rounded border-surface-strong text-ink focus:ring-ink/20">
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-meta font-bold">{{ $student->name }}</span>
                                    <span class="truncate text-label text-ink-400">{{ $student->matric_no ?? $student->email }}</span>
                                </span>
                                @if ($student->cgpa !== null)
                                    <span class="shrink-0 text-meta font-bold text-ink-600">{{ number_format((float) $student->cgpa, 2) }}</span>
                                @else
                                    <span class="shrink-0 text-label text-ink-300" title="No CGPA has been entered">none</span>
                                @endif
                            </label>
                        </li>
                    @endforeach
                </ul>
                <x-input-error :messages="$errors->get('students')" />
            </div>

            <div class="flex flex-wrap items-start gap-5">
                <div class="flex flex-col gap-2">
                    <x-input-label for="groups" value="Number of groups" />
                    <x-text-input id="groups" name="groups" type="number" min="1" max="50" required
                                  x-model.number="groups"
                                  class="max-w-[140px]" />
                    <p class="max-w-[260px] text-label leading-[1.45] text-ink-400" aria-live="polite">
                        <template x-if="picked >= 2">
                            <span>
                                <span x-text="picked"></span> selected, limit {{ $subject->group_size }} per group:
                                <span class="font-bold text-ink-600"
                                      x-text="min === max ? ('exactly ' + min) : (min + ' to ' + max)"></span>
                                groups.
                            </span>
                        </template>
                        <template x-if="picked < 2">
                            <span>Select at least two students.</span>
                        </template>
                    </p>
                    <x-input-error :messages="$errors->get('groups')" />
                </div>

                <div class="flex flex-col gap-2">
                    <x-input-label for="prefix" value="Group names" />
                    <x-text-input id="prefix" name="prefix" type="text" maxlength="30"
                                  :value="old('prefix', 'Group')"
                                  class="max-w-[200px]" />
                    <p class="text-label text-ink-400">Numbered from 1, skipping names already taken.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">Preview the groups</x-button>
                <span class="text-meta text-ink-400">Nothing is saved until you confirm the preview.</span>
            </div>
        </form>
            </section>
        @endif

        @endif

        {{-- Collapsed by default: the caveats that matter, kept on this screen. --}}
        <details class="group rounded-card border border-surface-line bg-surface-soft px-5 py-3.5">
            <summary class="flex cursor-pointer list-none items-center gap-2.5 text-meta font-bold text-ink-600">
                <x-icon name="alert" size="16" class="shrink-0 text-ink-300" />
                Good to know
                <span class="ml-auto text-label font-semibold text-ink-300 group-open:hidden">Show</span>
                <span class="ml-auto hidden text-label font-semibold text-ink-300 group-open:inline">Hide</span>
            </summary>

            <ul class="mt-3 flex list-disc flex-col gap-1.5 pl-5 text-meta leading-[1.55] text-ink-400">
                <li>
                    CGPA is entered by hand by the administrator. CollabSpace is not connected to any academic
                    system, so it is only as current as the last time somebody edited the account.
                </li>
                <li>
                    Only students the administrator enrolled in this section are listed, and only those without a
                    group in {{ $subject->code }}. Another section is a separate run: its students are never mixed in.
                </li>
                <li>Both ways create new groups. Groups that already exist, and their members, are never changed.</li>
            </ul>
        </details>
    </div>

</x-layouts.app>
