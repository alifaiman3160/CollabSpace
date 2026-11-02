{{--
    Shared fields for creating and editing a subject. Expects $subject (or null) and $lecturers.
    Two cards side by side on a wide screen, one scope between them: "Details" (code, name, lecturer, and the
    confirmation when a used subject changes lecturer) and "Sections" (a row per section).
    Sections: renaming keeps the section (its id travels with the row), so the students enrolled in it and the groups in
    it follow the new name. A section in use cannot be removed (the server says why).
--}}
@php($subject = $subject ?? null)
@php($usedBy = $subject ? (int) $subject->groups_count + (int) $subject->enrolments_count : 0)
@php($sectionRows = collect(old('sections', $subject ? $subject->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'students' => (int) $s->enrolments_count, 'groups' => (int) $s->groups_count])->all() : []))->map(fn ($row) => ['id' => $row['id'] ?? null, 'name' => (string) ($row['name'] ?? ''), 'students' => (int) ($row['students'] ?? 0), 'groups' => (int) ($row['groups'] ?? 0)])->values()->all())
@php($sectionErrors = collect($errors->get('sections.*'))->flatten()->merge($errors->get('sections'))->unique()->all())

<div class="grid items-stretch gap-[18px] min-[1000px]:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]" data-subject-form-grid
     x-data="{
         lecturer: '{{ old('lecturer_id', $subject?->lecturer_id) }}',
         original: '{{ $subject?->lecturer_id }}',
         inUse: {{ $usedBy > 0 ? 'true' : 'false' }},
         rows: {{ Js::from($sectionRows) }},
         add() { this.rows.push({ id: null, name: '', students: 0, groups: 0 }) },
         get moving() { return this.inUse && this.original !== '' && this.lecturer !== '' && this.lecturer !== this.original },
     }">

    <section class="flex min-w-0 flex-col content-start gap-4 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" aria-label="Details" data-details-card>
        <h2 class="text-h3 font-bold tracking-title">Details</h2>

        <div class="flex flex-col gap-2">
            <x-input-label for="code" value="Subject code" />
            <x-text-input id="code" name="code" type="text" required autofocus
                          :value="old('code', $subject?->code)" placeholder="SECJ3104" class="uppercase" />
            <x-input-error :messages="$errors->get('code')" />
        </div>

        <div class="flex flex-col gap-2">
            <x-input-label for="name" value="Subject name" />
            <x-text-input id="name" name="name" type="text" required
                          :value="old('name', $subject?->name)" placeholder="Application Development" />
            <x-input-error :messages="$errors->get('name')" />
        </div>

        <div class="flex flex-col gap-2">
            <x-input-label for="lecturer_id" value="Lecturer" />
            <select id="lecturer_id" name="lecturer_id" required x-model="lecturer"
                    class="h-[52px] w-full rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                <option value="">Choose a lecturer…</option>
                @foreach ($lecturers as $lecturer)
                    <option value="{{ $lecturer->id }}" @selected((string) old('lecturer_id', $subject?->lecturer_id) === (string) $lecturer->id)>
                        {{ $lecturer->name }} — {{ $lecturer->email }}{{ $lecturer->is_active ? '' : ' (deactivated)' }}
                    </option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('lecturer_id')" />

            @if ($lecturers->isEmpty())
                <p class="text-meta text-ink-400">There is no lecturer account yet. Create one under User accounts first.</p>
            @endif

            {{-- Moving a subject that already has groups or students: a deliberate second step. --}}
            <div class="flex flex-col gap-2 rounded-card border border-warning-bg bg-warning-bg px-4 py-3.5" x-show="moving" x-cloak data-lecturer-confirm>
                <p class="text-meta font-semibold text-warning-text">
                    Groups, marks and announcements stay with the subject and move to the new lecturer. The previous lecturer loses access to them.
                </p>
                <label class="flex items-center gap-2.5 text-meta font-bold text-ink">
                    <input type="checkbox" name="confirm_lecturer" value="1" @checked(old('confirm_lecturer'))
                           class="size-4 rounded border-surface-strong text-ink focus:ring-ink/20">
                    Yes, change the lecturer
                </label>
            </div>
            <x-input-error :messages="$errors->get('confirm_lecturer')" />
        </div>
    </section>

    <section class="flex min-w-0 flex-col gap-3 rounded-panel border border-surface-line bg-surface p-5 shadow-subtle" aria-label="Sections" data-subject-sections-block>
        <h2 class="text-h3 font-bold tracking-title">Sections</h2>
        <p class="text-meta leading-[1.5] text-ink-400">
            The class sections of this subject, for example 1A and 1B. Students are enrolled in one of them (under User
            accounts), and groups are formed within a section. Renaming a section keeps its students and groups.
        </p>

        <div class="flex flex-col gap-2.5">
            <template x-for="(row, i) in rows" :key="i">
                <div class="flex flex-wrap items-center gap-2.5" data-section-row>
                    <input type="hidden" x-bind:name="'sections[' + i + '][id]'" x-bind:value="row.id ?? ''">
                    <input type="text" x-bind:name="'sections[' + i + '][name]'" x-model="row.name" maxlength="20" aria-label="Section name"
                           placeholder="e.g. 1A" autocomplete="off"
                           class="h-[52px] w-40 rounded-[16px] border border-surface-line bg-surface px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                    <span class="text-label font-medium text-ink-400" x-show="row.students > 0 || row.groups > 0"
                          x-text="row.students + ' enrolled · ' + row.groups + ' in groups'"></span>
                    <x-button type="button" variant="secondary" class="!px-4 !py-2 !text-meta"
                              x-on:click="rows.splice(i, 1)" x-bind:aria-label="'Remove section ' + (i + 1)">Remove</x-button>
                </div>
            </template>
            <p class="text-meta text-ink-300" x-show="rows.length === 0" data-sections-empty>No sections yet.</p>
        </div>

        <div>
            <x-button type="button" variant="secondary" icon="plus" class="!px-4 !py-2 !text-meta" x-on:click="add()">Add section</x-button>
        </div>

        @foreach ($sectionErrors as $message)
            <p class="text-meta font-semibold text-danger-text">{{ $message }}</p>
        @endforeach
    </section>
</div>
