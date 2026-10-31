@props([
    // An Alpine expression: true while the panel is open ("open", "why", "sel !== ''" ...).
    'open',
    // An Alpine expression that is true only while the page is arriving already open, so nothing moves on load.
    'instant' => null,
])

{{--
    The one expanding panel. Its height grows from 0fr to 1fr and its content fades a little behind
    (see .cs-collapse in app.css: about 280ms ease-out opening, 200ms closing). While closed it is
    hidden, marked inert and aria-hidden, so nothing in it can be focused or read out. Put the
    trigger beside it, give the trigger aria-expanded and aria-controls, and use .cs-chevron on its
    arrow. With reduced motion the stylesheet makes every part of this instant.

    <x-collapse open="why" id="why-3">…</x-collapse>
--}}
<div {{ $attributes->merge(['class' => 'cs-collapse']) }} inert aria-hidden="true"
     x-bind:class="{ 'is-open': {{ $open }}@if ($instant), 'cs-instant': {{ $instant }}@endif }"
     x-bind:inert="! ({{ $open }})"
     x-bind:aria-hidden="! ({{ $open }}) ? 'true' : 'false'">
    <div class="cs-collapse__inner">
        {{ $slot }}
    </div>
</div>
