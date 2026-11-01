@props([
    'subject',              // the subject (its id decides the colour: SubjectPalette, id mod 6)
])

@php
    $colour = \App\Support\SubjectPalette::for($subject->getKey());
@endphp

{{-- The subject's code in its own pastel colour: the same colour as its card on the dashboard, wherever it is shown. --}}
<span {{ $attributes->class('inline-flex items-center rounded-pill px-2.5 py-0.5 text-label font-extrabold text-ink') }}
      style="background-color: {{ $colour['accent'] }};" data-subject-chip data-palette="{{ $colour['name'] }}"
      title="{{ $subject->name }}">{{ $subject->code }}</span>