<x-layouts.app :title="$document->title.' — CollabSpace'"
               :heading="$document->title"
               :subtitle="$group->subject->code.' — '.$group->name"
               :back="route('student.documents.group', $group)"
               back-label="All documents">

    <x-slot:actions>
        <x-button variant="secondary" :href="route('student.documents.group', $group)">All documents</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]" x-data="{ showHistory: false, confirm: null }">

        <x-flash />

        <div class="flex flex-col gap-[18px] lg:flex-row lg:items-start">

            {{-- Editor --}}
            <div class="flex min-w-0 flex-1 flex-col gap-5">
                <form method="POST" action="{{ route('student.documents.update', $document) }}"
                      class="flex flex-col gap-5">
                    @csrf
                    @method('PUT')

                    <div class="flex flex-col gap-2">
                        <x-input-label for="title" value="Title" />
                        <x-text-input id="title" name="title" type="text" required
                                      :value="old('title', $document->title)" />
                        <x-input-error :messages="$errors->get('title')" />
                    </div>

                    <x-rich-text name="content" :value="$body" label="Body" />
                    <x-input-error :messages="$errors->get('content')" />

                    <div class="flex flex-wrap items-center gap-3">
                        <x-button type="submit">Save</x-button>
                        <x-button variant="secondary" type="submit" name="close" value="1">Save and close</x-button>
                        <x-button variant="secondary" type="button" x-on:click="showHistory = ! showHistory">
                            <x-icon name="history" size="16" />
                            <span x-text="showHistory ? 'Hide history' : 'Version history'">Version history</span>
                        </x-button>
                        <span class="text-meta text-ink-400">
                            Saving with no changes to the body does not create a new version.
                        </span>
                    </div>
                </form>

                {{-- Deleting is not a save, so it sits below the editor and outside
                     the form — a DELETE cannot be posted by the same submit, and it
                     cannot be reached by pressing Enter in the title field. --}}
                <div class="flex flex-wrap items-center gap-3 border-t border-surface-line pt-5">
                    <x-button variant="secondary" class="!text-danger-text"
                        x-on:click="confirm = {
                            url: '{{ route('student.documents.destroy', $document) }}',
                            method: 'DELETE',
                            title: 'Delete this document?',
                            body: {{ Js::from('“'.$document->title.'” is your group\'s shared writing — everyone in '.$group->name.' loses it, not just you. This cannot be undone.') }},
                            consequences: {{ Js::from([
                                $versions->count().' '.str('version')->plural($versions->count()).' of history',
                                'the contribution it earned its editors',
                            ]) }},
                            action: 'Delete document',
                            tone: 'danger',
                        }">Delete</x-button>

                    <span class="text-meta text-ink-400">
                        Removes the document and its whole history for everyone in the group.
                    </span>
                </div>
            </div>

            {{-- Version history --}}
            <aside class="w-full shrink-0 lg:w-[320px]" x-show="showHistory" x-cloak>
                <div class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface-soft p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-h3 font-extrabold tracking-title">History</h2>
                        <x-badge tone="neutral">{{ $versions->count() }}</x-badge>
                    </div>

                    <p class="text-label leading-[1.5] text-ink-400">
                        Every save that changes the body is kept. Restoring an older version adds a
                        new one rather than overwriting, so nothing is ever lost.
                    </p>

                    <ol class="flex flex-col gap-2">
                        @foreach ($versions as $version)
                            @php($isCurrent = $loop->first)
                            <li class="flex flex-col gap-2 rounded-card bg-surface p-3.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge :tone="$isCurrent ? 'success' : 'neutral'">
                                        v{{ $version->version_no }}
                                    </x-badge>
                                    @if ($isCurrent)
                                        <span class="text-label font-semibold text-success-text">Current</span>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2">
                                    <x-avatar :name="$version->editor?->name ?? '?'" size="sm" />
                                    <span class="flex min-w-0 flex-col">
                                        <span class="truncate text-meta font-bold">
                                            {{ $version->editor?->name ?? 'Unknown' }}
                                        </span>
                                        <span class="text-tiny text-ink-300"
                                              title="{{ $version->created_at->format('j F Y, g:ia') }}">
                                            {{ $version->created_at->diffForHumans() }}
                                        </span>
                                    </span>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <x-button variant="secondary"
                                              class="!px-3 !py-1.5 !text-label"
                                              :href="route('student.documents.version', [$document, $version])">
                                        View
                                    </x-button>

                                    @unless ($isCurrent)
                                        <x-button variant="secondary" class="!px-3 !py-1.5 !text-label"
                                            x-on:click="confirm = {
                                                url: '{{ route('student.documents.restore', [$document, $version]) }}',
                                                method: 'POST',
                                                title: 'Restore version {{ $version->version_no }}?',
                                                body: {{ Js::from('The document body goes back to version '.$version->version_no.'. The current body is kept in the history as its own version, so this can be undone by restoring that one.') }},
                                                action: 'Restore',
                                                tone: 'neutral',
                                            }">
                                            Restore
                                        </x-button>
                                    @endunless
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </aside>
        </div>

        <x-confirm-dialog />
    </div>

</x-layouts.app>
