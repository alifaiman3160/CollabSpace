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

            {{-- Switch between threads when the student is in more than one group.
                 One group means there is nothing to choose, so the switcher stays
                 out of the way rather than showing a single dead tab. --}}
            @if ($groups->count() > 1)
                <div class="flex flex-col gap-2">
                    <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Your groups</span>
                    <div class="flex flex-wrap gap-1 rounded-pill bg-surface-muted p-[3px]">
                        @foreach ($groups as $g)
                            <a href="{{ route('student.chat.show', $g) }}"
                               title="{{ $g->subject->name }} · {{ $g->name }}"
                               @if ($g->is($group)) aria-current="page" @endif
                               class="flex h-8 items-center gap-1.5 rounded-pill px-[15px] text-meta font-bold transition-colors
                                      {{ $g->is($group) ? 'bg-ink text-white' : 'text-ink-600 hover:bg-surface-hover hover:text-ink' }}">
                                {{ $g->subject->code }}
                                <span class="font-semibold {{ $g->is($group) ? 'text-white/60' : 'text-ink-300' }}">
                                    {{ $g->name }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <livewire:group-chat :group="$group" />
        </div>

    @endif

</x-layouts.app>
