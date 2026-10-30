<x-layouts.app title="Documents — CollabSpace"
               heading="Documents"
               :subtitle="$hasGroup
                   ? $group->subject->code.' — '.$group->name
                   : 'You are not in a group yet.'">

    @if (! $hasGroup)

        <x-empty-state
            icon="file"
            title="You are not in a group yet"
            body="Documents belong to a group. Once your lecturer assigns you to one, your group's documents appear here." />

    @else

        <x-slot:actions>
            {{-- The header slot renders outside the body scope below, so this talks
                 to it through a window event rather than a shared Alpine scope. --}}
            <x-button x-data x-on:click="$dispatch('new-document')" icon="plus">New document</x-button>
        </x-slot:actions>

        <div class="flex flex-col gap-[18px]"
             x-data="{ creating: false, confirm: null }"
             x-on:new-document.window="creating = true; $nextTick(() => $refs.title?.focus())">

            <x-flash />

            <x-group-switcher :groups="$groups" :current="$group" route="student.documents.group" />

            {{-- New document: title only, then straight into the editor. --}}
            <form method="POST" action="{{ route('student.documents.store', $group) }}"
                  x-show="creating" x-cloak
                  class="flex flex-wrap items-start gap-3 rounded-panel border border-surface-line bg-surface shadow-subtle p-5">
                @csrf

                <div class="flex min-w-[260px] flex-1 flex-col gap-2">
                    <x-input-label for="title" value="Document title" />
                    <x-text-input id="title" name="title" type="text" required
                                  :value="old('title')"
                                  placeholder="Chapter 1 — Introduction"
                                  x-ref="title" />
                    <x-input-error :messages="$errors->get('title')" />
                </div>

                <div class="flex items-center gap-3 pt-[30px]">
                    <x-button type="submit">Create and open</x-button>
                    <x-button variant="secondary" type="button" x-on:click="creating = false">Cancel</x-button>
                </div>
            </form>

            @if ($errors->has('title'))
                {{-- Keep the form open when creation failed validation. --}}
                <div x-init="creating = true"></div>
            @endif

            @if ($documents->isEmpty())
                <x-empty-state
                    icon="file"
                    title="No documents yet"
                    body="Documents are your group's shared writing — chapters, reports, meeting notes. Everyone in {{ $group->name }} can edit them, and every save is kept in the history.">
                    <x-button class="mt-2" x-on:click="creating = true">Create the first document</x-button>
                </x-empty-state>
            @else
                <x-data-table :headings="['Title', 'Last edited by', 'Last edited', ['Versions', 'right'], '']">
                    @foreach ($documents as $document)
                        <tr>
                            <td>
                                <x-table.stack icon="file" :href="route('student.documents.edit', $document)">
                                    <x-slot:primary>{{ $document->title }}</x-slot:primary>
                                    Started by {{ $document->creator?->name ?? '—' }}
                                </x-table.stack>
                            </td>

                            <td>
                                @if ($document->lastEditor)
                                    <x-table.person :name="$document->lastEditor->name" />
                                @else
                                    <span class="text-meta text-ink-300">—</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap">
                                <span class="text-meta text-ink-400" title="{{ $document->updated_at->format('j F Y, g:ia') }}">
                                    {{ $document->updated_at->diffForHumans() }}
                                </span>
                            </td>

                            <td class="num text-meta font-semibold text-ink-400">{{ $document->versions_count }}</td>

                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    @php
                                        // Built here, then passed through Js::from below: Blade
                                        // compiles {{ }} inside a component attribute but not
                                        // @directives, so @js() there would render literally.
                                        $deleteBody = '“'.$document->title.'” is your group’s shared writing — '
                                            .'everyone in '.$group->name.' loses it, not just you. This cannot be undone.';
                                        $deleteConsequences = [
                                            $document->versions_count.' '.str('version')->plural($document->versions_count).' of history',
                                            'the contribution it earned its editors',
                                        ];
                                    @endphp

                                    <x-table.quiet-action icon="trash" aria-label="Delete {{ $document->title }}"
                                        x-on:click="confirm = {
                                            url: '{{ route('student.documents.destroy', $document) }}',
                                            method: 'DELETE',
                                            title: 'Delete this document?',
                                            body: {{ Js::from($deleteBody) }},
                                            consequences: {{ Js::from($deleteConsequences) }},
                                            action: 'Delete document',
                                            tone: 'danger',
                                        }">Delete</x-table.quiet-action>

                                    <x-button :href="route('student.documents.edit', $document)"
                                              class="!px-4 !py-2 !text-meta">Open</x-button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-data-table>
            @endif

            <x-confirm-dialog />
        </div>

    @endif

</x-layouts.app>
