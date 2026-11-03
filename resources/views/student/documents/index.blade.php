<x-layouts.app title="Documents — CollabSpace"
               heading="Documents"
               :subtitle="$hasGroup
                   ? $group->subject->code.' — '.$group->name.($group->section ? ' · Section '.$group->section->name : '')
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

            @include('student.documents._submit', ['group' => $group, 'documents' => $documents, 'submissions' => $submissions])

            @php
                $palette = \App\Support\SubjectPalette::for($group->subject_id);
                $previews = app(\App\Support\DocumentPreview::class);
            @endphp

            {{-- The group picker, then the toolbar (search, sort, count), then the cards. --}}
            <div class="flex flex-col gap-[18px]" x-data="documentGrid">
                <x-group-picker :groups="$groups" :current="$group" route="student.documents.group" :secondary="$secondary" />
            <form method="POST" action="{{ route('student.documents.store', $group) }}"
                  x-show="creating" x-cloak x-transition:enter="cs-t-enter" x-transition:enter-start="cs-t-from" x-transition:enter-end="cs-t-to" x-transition:leave="cs-t-leave" x-transition:leave-start="cs-t-to" x-transition:leave-end="cs-t-from"
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

                {{-- Search, sort and the count: one row, on the left edge with the cards, between the picker and the grid. --}}
                @if ($documents->isEmpty())
                    {{-- A friendly empty group: no dashed box, one clear next step. --}}
                    <div class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center" data-documents-empty>
                        <span class="flex size-14 items-center justify-center rounded-pill text-ink" style="background-color: {{ $palette['bg'] }};">
                            <x-icon name="file" size="24" />
                        </span>
                        <h2 class="text-h3 font-extrabold tracking-title">No documents yet</h2>
                        <p class="max-w-md text-body text-ink-400">
                            Documents are your group's shared writing: chapters, reports, meeting notes. Everyone in {{ $group->name }} can edit them, and every save is kept in the history.
                        </p>
                        <x-button class="mt-1" icon="plus" x-on:click="creating = true; $nextTick(() => $refs.title?.focus())">New document</x-button>
                    </div>
                @else
                    <x-document-list :count="$documents->count()">
                        @foreach ($documents as $i => $document)
                            @php
                                $preview = $previews->for($document);
                                $deleteBody = '“'.$document->title.'” will be removed from your group. Your lecturer can still see it.';
                            @endphp

                            <x-document-card :document="$document" :preview="$preview" :palette="$palette" :i="$i"
                                             :href="route('student.documents.edit', $document)" label="Open">
                                <x-slot:actions>
                                    <x-button :href="route('student.documents.edit', $document)" class="!px-4 !py-1.5 !text-meta">Open</x-button>

                                    <div class="flex items-center gap-0.5">
                                        {{-- A real link, so it works without JavaScript; the script fetches it to show "Preparing…" and a friendly message if it fails. --}}
                                        <span x-data="pdfDownload" class="inline-flex">
                                            <x-table.quiet-action tone="neutral" icon="download"
                                                :href="route('student.documents.pdf', $document)"
                                                x-on:click="start($event)"
                                                x-bind:aria-busy="loading"
                                                x-bind:class="loading ? 'pointer-events-none opacity-60' : ''"
                                                title="Download PDF"
                                                aria-label="Download {{ $document->title }} as a PDF"><span class="sr-only" x-text="loading ? 'Preparing…' : 'Download PDF'">Download PDF</span></x-table.quiet-action>
                                        </span>

                                        <x-table.quiet-action icon="trash" title="Delete" aria-label="Delete {{ $document->title }}"
                                            x-on:click="confirm = {
                                                url: '{{ route('student.documents.destroy', $document) }}',
                                                method: 'DELETE',
                                                title: 'Delete this document?',
                                                body: {{ Js::from($deleteBody) }},
                                                action: 'Delete document',
                                                tone: 'danger',
                                            }"><span class="sr-only">Delete</span></x-table.quiet-action>
                                    </div>
                                </x-slot:actions>
                            </x-document-card>
                        @endforeach

                        {{-- The last item of the grid: starts a document, exactly like the New document button above (same event, same
                             permission and route), and is not there while a search is active. Not a document: never counted. --}}
                        <button type="button" x-show="query.trim() === ''" x-on:click="$dispatch('new-document')" data-new-tile
                                class="cs-card-in interactive flex min-h-[20rem] flex-col items-center justify-center gap-3 rounded-panel border px-6 py-8 text-center"
                                style="order: 100000; --i: {{ min($documents->count(), 12) }}; background-color: {{ $palette['bg'] }}; border-color: {{ $palette['accent'] }};">
                            <span class="flex size-14 items-center justify-center rounded-pill bg-white/80 text-ink shadow-subtle"><x-icon name="plus" size="24" /></span>
                            <span class="text-nav font-extrabold tracking-title">New document</span>
                            <span class="text-label font-medium text-ink-600">Start a blank page</span>
                        </button>
                    </x-document-list>
                @endif
            </div>
            <x-confirm-dialog />
        </div>

    @endif

</x-layouts.app>
