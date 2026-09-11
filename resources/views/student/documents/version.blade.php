<x-layouts.app :title="$document->title.' · v'.$version->version_no.' — CollabSpace'"
               :heading="$document->title"
               :subtitle="'Version '.$version->version_no.' · '.$group->subject->code.' — '.$group->name"
               :back="route('student.documents.edit', $document)"
               back-label="Back to editor">

    <x-slot:actions>
        <x-button variant="secondary" :href="route('student.documents.edit', $document)">Back to editor</x-button>
    </x-slot:actions>

    <div class="flex flex-col gap-[18px]" x-data="{ confirm: null }">

        <x-flash />

        {{-- This screen is read-only: there is no editable surface anywhere on it. --}}
        <div class="flex flex-wrap items-center gap-3 rounded-card border border-surface-line bg-surface-soft px-5 py-4">
            <x-icon name="history" size="18" class="shrink-0 text-ink-300" />

            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <span class="text-nav font-bold">
                    Version {{ $version->version_no }} of {{ $versions->count() }}
                    @if ($isCurrent)
                        <span class="ml-1"><x-badge tone="success">Current content</x-badge></span>
                    @else
                        <span class="ml-1"><x-badge tone="neutral">Read only</x-badge></span>
                    @endif
                </span>
                <span class="text-meta text-ink-400">
                    Saved by {{ $version->editor?->name ?? 'Unknown' }}
                    on {{ $version->created_at->format('j F Y') }}
                    at {{ $version->created_at->format('g:ia') }}
                </span>
            </div>

            @unless ($isCurrent)
                <x-button
                    x-on:click="confirm = {
                        url: '{{ route('student.documents.restore', [$document, $version]) }}',
                        method: 'POST',
                        title: 'Restore version {{ $version->version_no }}?',
                        body: {{ Js::from('The document body goes back to version '.$version->version_no.'. The current body is kept in the history as its own version, so this can be undone by restoring that one.') }},
                        action: 'Restore this version',
                        tone: 'neutral',
                    }">
                    <x-icon name="restore" size="16" />
                    Restore
                </x-button>
            @endunless
        </div>

        <div class="flex flex-col gap-[18px] lg:flex-row lg:items-start">

            {{-- The version body, rendered read-only --}}
            <article class="min-w-0 flex-1 rounded-panel border border-surface-line bg-surface shadow-subtle p-7">
                @if (trim($body) === '')
                    <p class="text-body italic text-ink-300">This version is empty.</p>
                @else
                    <div class="doc-body text-[14.5px] leading-[1.65]">{!! $body !!}</div>
                @endif
            </article>

            {{-- Jump between versions --}}
            <aside class="w-full shrink-0 lg:w-[320px]">
                <div class="flex flex-col gap-3 rounded-panel border border-surface-line bg-surface-soft p-4">
                    <h2 class="text-h3 font-extrabold tracking-title">All versions</h2>

                    <ol class="flex flex-col gap-2">
                        @foreach ($versions as $other)
                            <li>
                                <a href="{{ route('student.documents.version', [$document, $other]) }}"
                                   class="flex items-center gap-2.5 rounded-card p-2.5 transition-colors
                                          {{ $other->is($version) ? 'bg-ink text-white' : 'bg-surface hover:bg-surface-hover' }}">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-pill text-label font-bold
                                                 {{ $other->is($version) ? 'bg-white/15 text-white' : 'bg-surface-muted text-ink-600' }}">
                                        {{ $other->version_no }}
                                    </span>
                                    <span class="flex min-w-0 flex-col">
                                        <span class="truncate text-meta font-bold">
                                            {{ $other->editor?->name ?? 'Unknown' }}
                                        </span>
                                        <span class="text-tiny {{ $other->is($version) ? 'text-white/70' : 'text-ink-300' }}">
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

        <x-confirm-dialog />
    </div>

</x-layouts.app>
