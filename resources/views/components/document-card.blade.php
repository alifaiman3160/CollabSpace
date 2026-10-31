@props([
    'document',            // with creator, lastEditor, versions_count, and the preview's content_head and page_breaks
    'preview',             // App\Support\DocumentPreview::for($document)
    'palette',             // the group's subject colour (SubjectPalette)
    'href',                // where the whole card goes
    'label' => 'Open',     // Open for a student's editor, Read for a lecturer's viewer
    'i' => 0,
])

{{--
    One document as a card: a small read-only first page (stacked edges and a page pill when there are several), the
    title, who started it, who edited it last and when, and the number of versions. The page puts its own actions in
    the actions slot (a student: Open, PDF, Delete; a lecturer: Read) and may add a quiet note line.
--}}
<article data-doc data-title="{{ $document->title }}" data-edited="{{ $document->updated_at->timestamp }}"
         style="--i: {{ min($i, 12) }};"
         class="cs-card-in relative flex min-w-0 flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle motion-colors hover:border-surface-strong">

    {{-- The whole card opens the document. Everything under it ignores the pointer, and the quiet buttons below sit above it. --}}
    <a href="{{ $href }}" class="interactive absolute inset-0 z-[1] rounded-panel"
   aria-label="{{ $label }} {{ $document->title }}"></a>

    {{-- A small, read-only first page: aria-hidden, no pointer, no focus. --}}
    <div class="pointer-events-none relative px-4 pb-4 pt-4" style="background-color: {{ $palette['bg'] }};" aria-hidden="true">
        <div class="doc-thumb">
            @if ($preview['pages'] > 1)
                <span class="doc-thumb-edge"></span>
                @if ($preview['pages'] > 2)
                    <span class="doc-thumb-edge"></span>
                @endif
            @endif

            <div class="doc-thumb-page" data-preview>
                @if ($preview['blank'])
                    <span class="flex size-full items-center justify-center text-ink-300"><x-icon name="file" size="26" /></span>
                @else
                    <div class="doc-thumb-inner doc-body">{!! $preview['html'] !!}</div>
                @endif
            </div>
        </div>

        @if ($preview['pages'] > 1)
            <span class="absolute right-3 top-3 rounded-pill bg-white/90 px-2 py-0.5 text-label font-bold text-ink-600 shadow-subtle" data-page-pill>{{ $preview['pages'] }} pages</span>
        @endif
    </div>

    <div class="pointer-events-none flex flex-1 flex-col gap-1 px-4 pb-2 pt-3">
        <h2 class="line-clamp-2 text-nav font-extrabold leading-snug tracking-title" title="{{ $document->title }}">{{ $document->title }}</h2>
        <p class="truncate text-label font-medium text-ink-400">Started by {{ $document->creator?->name ?? '—' }}</p>
    </div>

    <div class="pointer-events-none flex items-center gap-2 px-4 pb-2">
        @if ($document->lastEditor)
            <x-avatar :name="$document->lastEditor->name" size="sm" class="!size-6 !text-tiny" />
        @endif
        <span class="min-w-0 flex-1 truncate text-label font-medium text-ink-400" title="{{ $document->updated_at->format('j F Y, g:ia') }}">
            Edited {{ $document->updated_at->diffForHumans() }}
        </span>
        <span class="shrink-0 rounded-pill px-2 py-0.5 text-label font-bold text-ink-600"
              style="background-color: {{ $palette['bg'] }}; box-shadow: inset 0 0 0 1px {{ $palette['border'] }};"
              title="{{ $document->versions_count }} {{ Str::plural('version', $document->versions_count) }} saved" data-version-pill>v{{ $document->versions_count }}</span>
    </div>

    @isset($note)
        <div class="pointer-events-none px-4 pb-2 text-label font-medium text-ink-400" data-card-note>{{ $note }}</div>
    @endisset

    {{-- Actions: the page decides what they are. --}}
    <div class="relative z-[2] flex items-center justify-between gap-1.5 border-t border-surface-line px-3 py-2">
        {{ $actions }}
    </div>
</article>
