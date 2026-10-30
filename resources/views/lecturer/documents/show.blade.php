{{--
    One screen serves both the current body and any past version: they differ
    only in which content is rendered and which entry in the history is
    highlighted, and two near-identical templates would drift.
--}}
<x-layouts.app :title="$document->title.' — CollabSpace'"
               :heading="$document->title"
               :subtitle="$group->subject->code.' — '.$group->name"
               :back="route('lecturer.documents', $group)"
               back-label="All documents">

    <x-slot:actions>
        <x-button variant="secondary" :href="route('lecturer.documents', $group)">All documents</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]">

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
                <x-button variant="secondary" :href="route('lecturer.documents.show', $document)">
                    Back to current
                </x-button>
            @endif
        </div>

        <div class="flex flex-col gap-[18px] lg:flex-row lg:items-start">

            <article class="min-w-0 flex-1 rounded-panel border border-surface-line bg-surface shadow-subtle p-7">
                @if (trim($body) === '')
                    <p class="text-body italic text-ink-300">
                        {{ $version ? 'This version is empty.' : 'This document is empty.' }}
                    </p>
                @else
                    <div class="doc-body text-[14.5px] leading-[1.65]">{!! $body !!}</div>
                @endif
            </article>

            {{-- The history is the part that matters for marking: it shows who
                 actually wrote the thing, rather than who saved it last. --}}
            <aside class="w-full shrink-0 lg:w-[320px]">
                <div class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface-soft p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-h3 font-extrabold tracking-title">History</h2>
                        <x-badge tone="neutral">{{ $versions->count() }}</x-badge>
                    </div>

                    <p class="text-label leading-[1.5] text-ink-400">
                        Every save that changed the body is kept, with who saved it.
                    </p>

                    <ol class="flex flex-col gap-2">
                        @foreach ($versions as $other)
                            @php($isOpen = $version && $other->is($version))
                            <li>
                                <a href="{{ route('lecturer.documents.version', [$document, $other]) }}"
                                   class="flex items-center gap-2.5 rounded-card p-2.5 transition-colors
                                          {{ $isOpen ? 'bg-ink text-white' : 'bg-surface hover:bg-surface-hover' }}">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-pill text-label font-bold
                                                 {{ $isOpen ? 'bg-white/15 text-white' : 'bg-surface-muted text-ink-600' }}">
                                        {{ $other->version_no }}
                                    </span>
                                    <span class="flex min-w-0 flex-col">
                                        <span class="truncate text-meta font-bold">
                                            {{ $other->editor?->name ?? 'Unknown' }}
                                        </span>
                                        <span class="text-tiny {{ $isOpen ? 'text-white/70' : 'text-ink-300' }}">
                                            {{ $other->created_at->diffForHumans() }}
                                        </span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </aside>
        </div>
    </div>

</x-layouts.app>
