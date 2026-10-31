@props([
    'tag' => 'div',
    // false: it fades in, and disappears at once when it goes. Right where one line replaces another.
    'leave' => true,
])

{{--
    Something that comes and goes with an x-show: a short fade and a slight slide in (about 150ms),
    a quicker fade out. The classes live in app.css (.cs-t-*). Use it for notes, hints and fields
    that appear after a choice; use <x-collapse> when the thing has a height worth animating.

    <x-fade tag="span" x-show="m === 'add'" x-cloak>…</x-fade>
--}}
<{{ $tag }} {{ $attributes->merge(array_filter([
    'x-transition:enter' => 'cs-t-enter',
    'x-transition:enter-start' => 'cs-t-from',
    'x-transition:enter-end' => 'cs-t-to',
    'x-transition:leave' => $leave ? 'cs-t-leave' : null,
    'x-transition:leave-start' => $leave ? 'cs-t-to' : null,
    'x-transition:leave-end' => $leave ? 'cs-t-from' : null,
])) }}>{{ $slot }}</{{ $tag }}>
