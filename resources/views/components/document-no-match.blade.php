{{-- What a title search says when nothing matches (inside x-data="documentGrid"). --}}
<div x-show="shown === 0" x-cloak class="flex flex-col items-center gap-3 px-2 py-12 text-center" data-no-match>
    <span class="flex size-12 items-center justify-center rounded-pill bg-surface-muted text-ink-300"><x-icon name="search" size="20" /></span>
    <p class="text-body font-semibold text-ink-600" x-text="'No documents match “' + query.trim() + '”'">No documents match your search.</p>
    <x-button variant="secondary" type="button" class="!px-4 !py-2 !text-meta" x-on:click="clear()">Clear search</x-button>
</div>
