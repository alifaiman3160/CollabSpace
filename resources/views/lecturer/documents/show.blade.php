{{--
    One screen serves both the current body and any past version: they differ only in which content is rendered and
    which entry in the history is highlighted, and two near-identical templates would drift. Every link here is a
    lecturer route (under Monitor progress); nothing points at the students' editor.
--}}
<x-layouts.app :title="$document->title.' — CollabSpace'"
               :heading="$document->title"
               :subtitle="$group->subject->code.' — '.$group->name">

    <x-slot:breadcrumb>
        <x-breadcrumb :items="[['Monitor progress', route('lecturer.progress')], [$group->name, route('lecturer.progress.show', $group)], ['Documents', route('lecturer.progress.documents', $group)], [$document->title, null]]" />
    </x-slot:breadcrumb>

    <x-slot:actions>
        <x-button variant="secondary" :href="route('lecturer.progress.documents', $group)">All documents</x-button>
    </x-slot:actions>

    @php
        $empty = trim($body) === '';
        $palette = \App\Support\SubjectPalette::for($group->subject_id);
    @endphp

    <div class="flex flex-col gap-[18px]">

        @if ($document->trashed())
            {{-- Removed by the group but kept: the lecturer can still read it, and nobody can edit or restore it from here. --}}
            <div class="flex items-start gap-3 rounded-card border border-surface-line bg-warning-bg px-5 py-4" data-deleted-banner>
                <x-icon name="trash" size="18" class="mt-0.5 shrink-0 text-warning-text" />
                <span class="text-meta font-semibold text-warning-text">
                    Deleted by {{ $document->deleter?->name ?? 'a member of the group' }}
                    on {{ $document->deleted_at->format('j F Y') }} at {{ $document->deleted_at->format('g:ia') }}.
                    The group no longer sees it.
                </span>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4">
            <x-icon :name="$version ? 'history' : 'eye'" size="18" class="shrink-0 text-ink-300" />

            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-nav font-bold">
                    @if ($version)
                        Version {{ $version->version_no }} of {{ $versions->count() }}
                        <span class="ml-1"><x-badge tone="neutral">Read only</x-badge></span>
                    @else
                        Current content
                        <span class="ml-1"><x-badge tone="neutral">Read only</x-badge></span>
                    @endif
                </span>

                <span class="text-meta text-ink-400">
                    @if ($version)
                        Saved by {{ $version->editor?->name ?? 'Unknown' }}
                        on {{ $version->created_at->format('j F Y') }}
                        at {{ $version->created_at->format('g:ia') }}
                    @else
                        Last edited by {{ $document->lastEditor?->name ?? 'Unknown' }}
                        on {{ $document->updated_at->format('j F Y') }}
                        at {{ $document->updated_at->format('g:ia') }}
                    @endif
                </span>
            </div>

            @if ($version)
                <x-button variant="secondary" :href="route('lecturer.progress.documents.show', [$group, $document])">
                    Back to current
                </x-button>
            @endif
        </div>

        <div class="flex flex-col gap-[18px] lg:flex-row lg:items-start">

            {{-- The page, on the same soft grey stage as the editor: a white A4 sheet, read only. --}}
            <div class="min-w-0 flex-1 overflow-clip rounded-panel border border-surface-line" data-viewer
                 x-data="documentViewer({ html: @js($body) })"
                 x-on:keydown.alt.arrow-right.window="next()" x-on:keydown.alt.arrow-left.window="prev()">
                <div class="doc-stage">
                    <div class="doc-viewport">
                        <div class="doc-side doc-side-left" aria-hidden="true">
                            <template x-for="n in plan.edgesLeft" :key="'l' + n">
                                <span class="doc-edge" x-bind:style="'--layer: ' + n"></span>
                            </template>
                            <div class="doc-peek doc-peek-left" x-show="plan.previous !== null" x-cloak x-on:click="prev()"></div>
                        </div>

                        <div x-ref="sheet" class="doc-body doc-page text-ink" data-sheet>
                            @if ($empty)
                                <p class="text-body italic text-ink-300" data-empty>{{ $version ? 'This version is empty.' : 'This document is empty.' }}</p>
                            @else
                                {!! $body !!}
                            @endif
                        </div>

                        <div class="doc-side doc-side-right" aria-hidden="true">
                            <div class="doc-peek doc-peek-right" x-show="plan.next !== null" x-cloak x-on:click="next()"></div>
                            <template x-for="n in plan.edgesRight" :key="'r' + n">
                                <span class="doc-edge" x-bind:style="'--layer: ' + n"></span>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="doc-controls" x-show="pages.length > 1" x-cloak>
                    <div class="doc-controls-grid">
                        <div class="col-start-2 flex items-center justify-center gap-2" role="group" aria-label="Pages">
                            <button type="button" x-on:click="prev()" :disabled="current === 0" title="Previous page (Alt + Left)" aria-label="Previous page"
                                    class="interactive flex size-8 items-center justify-center rounded-pill text-ink-600 hover:bg-surface-hover hover:text-ink disabled:pointer-events-none disabled:opacity-40">
                                <x-icon name="arrow-left" size="15" />
                            </button>
                            <span class="min-w-[104px] text-center text-meta font-semibold text-ink-600" aria-live="polite">
                                Page <span x-text="current + 1"></span> of <span x-text="pages.length"></span>
                            </span>
                            <button type="button" x-on:click="next()" :disabled="current >= pages.length - 1" title="Next page (Alt + Right)" aria-label="Next page"
                                    class="interactive flex size-8 items-center justify-center rounded-pill text-ink-600 hover:bg-surface-hover hover:text-ink disabled:pointer-events-none disabled:opacity-40">
                                <x-icon name="arrow-right" size="15" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- The history is the part that matters for marking: it shows who actually wrote the thing, rather than
                 who saved it last. --}}
            <x-panel title="History" :count="$versions->count()" class="!min-h-0 w-full shrink-0 lg:max-h-[52rem] lg:w-[320px]" data-history-panel>
                <p class="pb-2 text-label leading-[1.5] text-ink-400">Every save that changed the body is kept, with who saved it.</p>

                <ol class="flex flex-col gap-2">
                    @foreach ($versions as $other)
                        @php($isOpen = $version && $other->is($version))
                        <li>
                            <a href="{{ route('lecturer.progress.documents.version', [$group, $document, $other]) }}"
                               class="interactive flex items-center gap-2.5 rounded-card p-2.5 transition-colors
                                      {{ $isOpen ? 'bg-ink text-white' : 'bg-surface-soft hover:bg-surface-hover' }}">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-pill text-label font-bold
                                             {{ $isOpen ? 'bg-white/15 text-white' : 'bg-surface text-ink-600' }}">
                                    {{ $other->version_no }}
                                </span>
                                <span class="flex min-w-0 flex-col">
                                    <span class="truncate text-meta font-bold">{{ $other->editor?->name ?? 'Unknown' }}</span>
                                    <span class="text-tiny {{ $isOpen ? 'text-white/70' : 'text-ink-300' }}">{{ $other->created_at->diffForHumans() }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </x-panel>
        </div>
    </div>

</x-layouts.app>