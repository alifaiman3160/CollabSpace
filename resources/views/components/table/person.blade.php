@props([
    'name',
    'href' => null,
])

{{-- Avatar, name, and a muted line under it (email, matric number, group...). --}}
<div {{ $attributes->class('flex min-w-0 items-center gap-3') }}>
    <x-avatar :name="$name" size="sm" class="shrink-0" />

    <x-table.stack :href="$href">
        <x-slot:primary>{{ $name }}@isset($suffix) {{ $suffix }}@endisset</x-slot:primary>
        {{ $slot }}
    </x-table.stack>
</div>
