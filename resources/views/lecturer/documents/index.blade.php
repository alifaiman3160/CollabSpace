<x-layouts.app title="Group documents — CollabSpace"
               heading="Documents"
               :subtitle="$group->subject->code.' — '.$group->name.($group->section ? ' · Section '.$group->section->name : '')">

    <x-slot:breadcrumb>
        <x-breadcrumb :items="[['Monitor progress', route('lecturer.progress')], [$group->name, route('lecturer.progress.show', $group)], ['Documents', null]]" />
    </x-slot:breadcrumb>

    <x-slot:actions>
        <x-button variant="secondary" :href="route('lecturer.progress.show', $group)">Back to monitoring</x-button>
    </x-slot:actions>

    @php
        $palette = \App\Support\SubjectPalette::for($group->subject_id);
        $previews = app(\App\Support\DocumentPreview::class);
    @endphp

    <div class="flex flex-col gap-[18px]">

        {{-- Said plainly, so nobody goes looking for an edit button. --}}
        <div class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4" data-read-only-note>
            <x-icon name="eye" size="18" class="shrink-0 text-ink-300" />
            <p class="min-w-0 flex-1 text-meta text-ink-400">
                Read-only. You can open any document and any of its past versions, but the
                writing stays with the group — nothing here changes what they submit, and
                opening a document records no activity against them.
            </p>
        </div>

        @if ($documents->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center" data-documents-empty>
                <span class="flex size-14 items-center justify-center rounded-pill text-ink" style="background-color: {{ $palette['bg'] }};">
                    <x-icon name="file" size="24" />
                </span>
                <h2 class="text-h3 font-extrabold tracking-title">No documents yet</h2>
                <p class="max-w-md text-body text-ink-400">
                    {{ $group->name }} has not created any shared documents. Their work may still be in the chat or on the task board.
                </p>
            </div>
        @else
            <div class="w-full" x-data="documentGrid">
                <x-document-list :count="$documents->count()">
                    @foreach ($documents as $i => $document)
                        <x-document-card :document="$document" :preview="$previews->for($document)" :palette="$palette" :i="$i"
                                         :href="route('lecturer.progress.documents.show', [$group, $document])" label="Read">
                            <x-slot:actions>
                                <x-button :href="route('lecturer.progress.documents.show', [$group, $document])" class="!px-4 !py-1.5 !text-meta">Read</x-button>
                            </x-slot:actions>
                        </x-document-card>
                    @endforeach
                </x-document-list>
            </div>
        @endif

        {{-- What the group deleted, kept for you: closed until asked for, and read only like the rest. --}}
        @if ($deleted->isNotEmpty())
            <section class="flex flex-col gap-3" x-data="{ open: false }" data-deleted-documents-section>
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="deleted-documents-grid"
                        class="interactive flex w-fit items-center gap-2 rounded-pill bg-surface-muted px-4 py-2 text-meta font-bold text-ink-600 hover:bg-surface-hover">
                    Deleted documents
                    <span class="rounded-pill bg-surface px-2 py-0.5 text-label font-bold" data-deleted-count>{{ $deleted->count() }}</span>
                    <svg class="cs-chevron size-4 shrink-0" x-bind:class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                <x-collapse open="open" id="deleted-documents-grid" data-deleted-documents>
                    <x-document-grid class="pt-1" aria-label="Deleted documents" data-deleted-grid>
                        @foreach ($deleted as $i => $document)
                            <x-document-card :document="$document" :preview="$previews->for($document)" :palette="$palette" :i="$i"
                                             :href="route('lecturer.progress.documents.show', [$group, $document])" label="Read">
                                <x-slot:note>
                                    Deleted by {{ $document->deleter?->name ?? 'a member' }} on {{ $document->deleted_at->format('j M Y') }}
                                </x-slot:note>

                                <x-slot:actions>
                                    <x-button variant="secondary" :href="route('lecturer.progress.documents.show', [$group, $document])" class="!px-4 !py-1.5 !text-meta">Read</x-button>
                                </x-slot:actions>
                            </x-document-card>
                        @endforeach
                    </x-document-grid>
                </x-collapse>
            </section>
        @endif
    </div>

</x-layouts.app>