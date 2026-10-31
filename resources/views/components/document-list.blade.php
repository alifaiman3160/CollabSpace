@props(['count'])

{{--
    The list of document cards, in one vertical stack: the toolbar (search, sort and the count, one row on the left
    edge), what a search that finds nothing says, then the grid. Put inside x-data="documentGrid". The page puts its
    cards (and, for a student, the New document tile) in the slot; a lecturer's cards only differ in their actions.
--}}
<div class="flex w-full flex-col gap-[18px]" data-document-list>
    <x-document-toolbar :count="$count" />

    <x-document-no-match />

    <x-document-grid>
        {{ $slot }}
    </x-document-grid>
</div>