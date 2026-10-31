@props(['count'])

{{-- Search, sort and the count for a list of document cards (inside x-data="documentGrid"). One row on the left edge. --}}
<div class="flex flex-wrap items-center gap-2.5" role="search" data-documents-toolbar>
    <div class="relative w-full max-w-[20rem] sm:w-80">
        <label class="sr-only" for="document-search">Search documents by title</label>
        <x-icon name="search" size="16" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-300" />
        <input id="document-search" type="search" x-model="query" x-on:input.debounce.150ms="apply()" x-on:keydown.escape="clear()"
               placeholder="Search titles" autocomplete="off"
               class="h-10 w-full rounded-pill border border-surface-line bg-surface pl-10 pr-10 text-meta font-medium text-ink placeholder:text-ink-300 focus:border-ink-300 focus:outline-none focus:ring-2 focus:ring-ink/15 [&::-webkit-search-cancel-button]:hidden" />
        <button type="button" x-show="query !== ''" x-cloak x-on:click="clear()" aria-label="Clear search" title="Clear search"
                class="interactive absolute right-2 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-pill text-ink-400 hover:bg-surface-hover hover:text-ink">
            <span aria-hidden="true" class="text-body leading-none">×</span>
        </button>
    </div>

    <div class="flex items-center gap-2">
        <label class="sr-only" for="document-sort">Sort documents</label>
        <select id="document-sort" x-model="sort" x-on:change="apply()"
                class="h-10 rounded-pill border border-surface-line bg-surface py-0 pl-3.5 pr-9 text-meta font-semibold text-ink-600 focus:outline-none focus:ring-2 focus:ring-ink/15">
            <option value="edited">Last edited</option>
            <option value="title">Title A to Z</option>
        </select>
    </div>

    <span class="ml-auto text-meta font-semibold text-ink-400" aria-live="polite" data-documents-count
          x-text="shown + ' ' + (shown === 1 ? 'document' : 'documents')">{{ $count }} {{ Str::plural('document', $count) }}</span>
</div>
