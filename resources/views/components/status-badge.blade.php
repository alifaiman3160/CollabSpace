@props(['active' => true, 'pending' => false])

{{-- Account status: a pill led by a status dot. --}}
@if($active)
    <x-table.dot-pill tone="success">Active</x-table.dot-pill>
@else
    <x-table.dot-pill tone="danger">Inactive</x-table.dot-pill>
@endif

@if($pending)
    {{-- Still carrying the temporary password issued at creation. --}}
    <x-table.dot-pill tone="neutral" title="Has not signed in and changed the temporary password yet">Pending</x-table.dot-pill>
@endif
