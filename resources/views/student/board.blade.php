<x-layouts.app title="Task board — CollabSpace"
               heading="Task board"
               :subtitle="$hasGroup ? $group->subject->code.' — '.$group->name.($group->section ? ' · Section '.$group->section->name : '') : 'You are not in a group yet.'">

    @if (! $hasGroup)

        <x-empty-state
            icon="folder"
            title="You are not in a group yet"
            body="A task board belongs to a group. Once your lecturer assigns you to one, the board appears here." />

    @else

        <x-slot:actions>
            <x-button variant="secondary" :href="route('student.workspace.show', $group)">Workspace</x-button>
        </x-slot:actions>

        <div class="flex flex-col gap-[18px]">
            <x-group-picker :groups="$groups" :current="$group" route="student.board.show" :secondary="$secondary" />

            {{-- The project brief for this group's subject, closed until opened. --}}
            <x-project-brief :subject="$group->subject" />

            {{-- Status changes run through Livewire, so the page never reloads. --}}
            <livewire:task-board :group="$group" />
        </div>

    @endif

</x-layouts.app>
