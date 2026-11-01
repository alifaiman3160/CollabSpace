{{-- Shared fields for creating and editing a subject. Expects $subject or null. --}}
@php($subject = $subject ?? null)

<div class="flex max-w-xl flex-col gap-5 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">

    <div class="flex flex-col gap-2">
        <x-input-label for="code" value="Subject code" />
        <x-text-input id="code" name="code" type="text" required autofocus
                      :value="old('code', $subject?->code)"
                      placeholder="SECJ3104"
                      class="uppercase" />
        <x-input-error :messages="$errors->get('code')" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="name" value="Subject name" />
        <x-text-input id="name" name="name" type="text" required
                      :value="old('name', $subject?->name)"
                      placeholder="Application Development" />
        <x-input-error :messages="$errors->get('name')" />
    </div>

    {{-- The project. Both optional: a subject is usually created so groups
         can be set up, and the brief is written afterwards. --}}
    <div class="flex flex-col gap-2">
        <x-input-label for="brief" value="Project brief" />
        <textarea id="brief" name="brief" rows="5"
                  placeholder="What the groups have to produce, and what it is marked on."
                  class="w-full rounded-[16px] bg-surface border border-surface-line px-[18px] py-3.5 text-[14px] font-medium text-ink placeholder:text-ink-300 focus:outline-none focus:ring-2 focus:ring-ink/10">{{ old('brief', $subject?->brief) }}</textarea>
        <p class="text-meta text-ink-400">
            Shown to every student in this subject, at the top of their workspace and task board.
            Leave blank until you have written it.
        </p>
        <x-input-error :messages="$errors->get('brief')" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="project_deadline" value="Group project due date" />
        <x-text-input id="project_deadline" name="project_deadline" type="date"
                      :value="old('project_deadline', $subject?->project_deadline?->format('Y-m-d'))"
                      class="max-w-[220px]" />
        <p class="text-meta text-ink-400" data-project-due-help>
            Students see a countdown on their dashboard and get a reminder
            {{ (int) config('notifications.project_reminder_days') }} days before. It does not lock tasks or documents.
            Optional.
        </p>
        <x-input-error :messages="$errors->get('project_deadline')" />
    </div>

    <div class="flex flex-col gap-2">
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
