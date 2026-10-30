<x-layouts.app title="Group documents — CollabSpace"
               heading="Documents"
               :subtitle="$group->subject->code.' — '.$group->name"
               :back="route('lecturer.progress.show', $group)"
               back-label="Back to monitoring">

    <x-slot:actions>
        <x-button variant="secondary" :href="route('lecturer.progress.show', $group)">Back to monitoring</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]">

        {{-- Said plainly, so nobody goes looking for an edit button. --}}
        <div class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4">
            <x-icon name="eye" size="18" class="shrink-0 text-ink-300" />
            <p class="min-w-0 flex-1 text-meta text-ink-400">
                Read-only. You can open any document and any of its past versions, but the
                writing stays with the group — nothing here changes what they submit, and
                opening a document records no activity against them.
            </p>
        </div>

        @if ($documents->isEmpty())
            <x-empty-state
                icon="file"
                title="No documents yet"
                body="{{ $group->name }} has not created any shared documents. Their work may still be in the chat or on the task board." />
        @else
            <x-data-table :headings="['Title', 'Last edited by', 'Last edited', ['Versions', 'right'], '']">
                @foreach ($documents as $document)
                    <tr>
                        <td>
                            <x-table.stack icon="file" :href="route('lecturer.documents.show', $document)">
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
                            <div class="flex justify-end">
                                <x-button :href="route('lecturer.documents.show', $document)"
                                          class="!px-4 !py-2 !text-meta">Read</x-button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif
    </div>

</x-layouts.app>
