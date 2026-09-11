@props(['active' => true, 'pending' => false])

@if($active)
    <x-badge tone="success">Active</x-badge>
@else
    <x-badge tone="danger">Inactive</x-badge>
@endif

@if($pending)
    {{-- Still carrying the temporary password issued at creation. --}}
    <x-badge tone="neutral" title="Has not signed in and changed the temporary password yet">Pending</x-badge>
@endif
