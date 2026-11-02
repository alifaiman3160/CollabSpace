{{--
    The "Subject settings" card: the settings a lecturer may change on the subject itself (the code, name, lecturer and
    sections are read-only in the side card). Brief, dates and files belong to each assessment, further down. Expects
    $subject (with its assessments). It sits inside the one form of the edit page, which the header's Save button also
    submits (form="subject-form"). A subject with no assessment at all (it should not happen) keeps the old brief and due
    date fields so nothing is lost.
--}}
@php($subject = $subject ?? null)
@php($legacy = ! ($subject?->assessments?->isNotEmpty() ?? false))

<section class="flex flex-col gap-4 rounded-panel border border-surface-line bg-surface p-4 shadow-subtle" aria-label="Subject settings" data-details-card>
    <h2 class="text-h3 font-bold tracking-title">Subject settings</h2>

    @if ($legacy)
        <div class="flex flex-col gap-2">
            <x-input-label for="brief" value="Project brief" />
            <textarea id="brief" name="brief" rows="5"
                      placeholder="What the groups have to produce, and what it is marked on."
                      class="w-full rounded-[16px] bg-surface border border-surface-line px-[18px] py-3.5 text-[14px] font-medium text-ink placeholder:text-ink-300 focus:outline-none focus:ring-2 focus:ring-ink/10">{{ old('brief', $subject?->brief) }}</textarea>
            <x-input-error :messages="$errors->get('brief')" />
        </div>

        <div class="flex min-w-0 flex-col gap-2">
            <x-input-label for="project_deadline" value="Group project due date" />
            <x-text-input id="project_deadline" name="project_deadline" type="date"
                          :value="old('project_deadline', $subject?->projectDeadline()?->format('Y-m-d'))" class="max-w-[220px]" />
            <p class="text-meta text-ink-400" data-project-due-help>
                Students see a countdown on their dashboard and get a reminder
                {{ (int) config('notifications.project_reminder_days') }} days before. It does not lock tasks or documents.
                Optional.
            </p>
            <x-input-error :messages="$errors->get('project_deadline')" />
        </div>
    @endif

    {{-- The two settings side by side on a wide screen, one under another on a narrow one. --}}
    <div class="grid items-start gap-4 sm:grid-cols-2" data-short-fields>
        <div class="flex min-w-0 flex-col gap-2">
            <x-input-label for="semester_start_date" value="Semester start date" />
            <x-text-input id="semester_start_date" name="semester_start_date" type="date"
                          :value="old('semester_start_date', $subject?->semester_start_date?->format('Y-m-d'))"
                          class="max-w-[220px]" />
            <p class="text-meta text-ink-400" data-semester-help>
                The first day of Week 1. Students and you will see the current week on each subject. Leave blank to hide it.
            </p>
            <x-input-error :messages="$errors->get('semester_start_date')" />
        </div>

        <div class="flex min-w-0 flex-col gap-2">
            <x-input-label for="group_size" value="Default group size" />
            <x-text-input id="group_size" name="group_size" type="number" min="2" max="10" required
                          :value="old('group_size', $subject?->group_size ?? 4)"
                          class="max-w-[140px]" />
            <p class="text-meta text-ink-400">
                Suggested when you create a group under this subject. Each group can still override it.
            </p>
            <x-input-error :messages="$errors->get('group_size')" />
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3 border-t border-surface-line pt-4">
        <x-button type="submit">Save changes</x-button>
        <x-button variant="secondary" :href="route('lecturer.subjects')">Cancel</x-button>
    </div>
</section>
