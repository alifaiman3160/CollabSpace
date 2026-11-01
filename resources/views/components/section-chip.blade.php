@props([
    'section' => null,      // the Section
    'compact' => false,     // "Sec 1A" for tight places (the group switcher); otherwise "Section 1A"
    'code' => null,         // the subject's code for the tooltip, when the page has it to hand
])

{{--
    The one way a section is shown, everywhere: the word "Section" and its name, a small people icon, a neutral
    outline. Deliberately not in the subject's palette colour, so it never reads as another subject tag. Its tooltip
    says which subject it belongs to ("Section 1A of SECJ2203"); the code is taken from the section's subject when
    that is loaded, or passed in as :code, and left out otherwise (no query per chip).
--}}
@if ($section)
    @php
        $code ??= $section->relationLoaded('subject') ? $section->subject?->code : null;
        $tip = 'Section '.$section->name.($code ? ' of '.$code : '');
    @endphp
    <span {{ $attributes->class('inline-flex items-center gap-1 whitespace-nowrap rounded-pill border border-surface-strong bg-surface px-2 py-px text-label font-bold text-ink-600') }}
          data-section-chip data-tooltip="{{ $tip }}"><x-icon name="users" size="11" class="shrink-0" />{{ $compact ? 'Sec' : 'Section' }} {{ $section->name }}</span>
@endif
