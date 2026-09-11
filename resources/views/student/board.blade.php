<x-layouts.app title="Task board — CollabSpace"
               heading="Task board"
               :subtitle="$hasGroup ? $group->subject->code.' — '.$group->name : 'You are not in a group yet.'">

    @if (! $hasGroup)

        <x-empty-state
            icon="folder"
            title="You are not in a group yet"
            body="A task board belongs to a group. Once your lecturer assigns you to one, the board appears here." />

    @else

        <x-slot:actions>
            <x-button variant="secondary" :href="route('student.workspace.show', $group)">Workspace</x-button>
        </x-slot:actions>

        {{-- Status changes run through Livewire, so the page never reloads. --}}
        <livewire:task-board :group="$group" />

    @endif

</x-layouts.app>
