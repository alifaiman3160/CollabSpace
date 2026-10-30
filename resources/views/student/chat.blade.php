<x-layouts.app title="Group chat — CollabSpace"
               heading="Group chat"
               :subtitle="$hasGroup
                   ? $group->subject->code.' — '.$group->name
                   : 'You are not in a group yet.'">

    @if (! $hasGroup)

        <x-empty-state
            icon="chat"
            title="You are not in a group yet"
            body="Chat belongs to a group. Once your lecturer assigns you to one, your group's thread appears here." />

    @else

        <x-slot:actions>
            <x-button variant="secondary" :href="route('student.workspace.show', $group)">Workspace</x-button>
        </x-slot:actions>

        <div class="flex flex-col gap-[18px]">

            <x-flash />

            <x-group-switcher :groups="$groups" :current="$group" route="student.chat.show" />

            <livewire:group-chat :group="$group" />
        </div>

    @endif

</x-layouts.app>
