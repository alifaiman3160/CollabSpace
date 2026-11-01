@props([
    'groups',              // every group the student belongs to (with their subject)
    'current',             // the one being looked at
    'route' => null,       // link mode: the route name each card links to (the group is its parameter)
    'select' => null,      // in-place mode: an Alpine or Livewire expression run on click, {id} standing for the group's id
    'secondary' => [],     // optional quiet line per card: group id => text ("4 members"). Omitted, the line is omitted.
])

{{--
    The one way to pick which group's screen you are looking at, on every student page that has one (My workspace,
    Task board, Documents). A row of small cards in the group's own soft colour (the subject's, from SubjectPalette, so a
    subject is the same colour everywhere), the subject code, the group's name, and a quiet line the page chooses.

    Link mode goes to the page for that group, as these pages always have. In-place mode runs an expression instead and
    marks the card with aria-pressed. Either way the markup is this one block.

    Arrow keys move between cards, Enter picks. One group still shows its card, without anything that suggests there
    is a choice to make.
--}}
@php
    $solo = $groups->count() === 1;
@endphp

@if ($groups->isNotEmpty())
    <div class="flex min-w-0 flex-col gap-2" data-group-picker x-data="groupPicker" x-on:keydown="move($event)">
        <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Your groups</span>

        <div class="flex flex-wrap gap-2.5" role="group" aria-label="Your groups">
            @foreach ($groups as $g)
                @php
                    $isCurrent = $g->is($current);
                    $colour = \App\Support\SubjectPalette::for($g->subject_id);
                    $text = $secondary[$g->getKey()] ?? null;
                    $classes = 'cs-group-card flex w-[16rem] min-w-[13rem] max-w-full items-center gap-3 rounded-card border p-3 text-left interactive'
                        .($solo ? ' is-solo' : '');
                    $style = "--pal-bg: {$colour['bg']}; --pal-accent: {$colour['accent']}; --pal-border: {$colour['border']};";
                @endphp

                @if ($route)
                    <a href="{{ route($route, $g) }}" title="{{ $g->subject->name }} · {{ $g->name }}"
                       @if ($isCurrent) aria-current="page" @endif
                       class="{{ $classes }} interactive" style="{{ $style }}" data-group-card data-palette="{{ $colour['name'] }}">
                @else
                    <button type="button" title="{{ $g->subject->name }} · {{ $g->name }}"
                            x-on:click="{{ str_replace('{id}', $g->getKey(), $select) }}" aria-pressed="{{ $isCurrent ? 'true' : 'false' }}"
                            class="{{ $classes }} interactive" style="{{ $style }}" data-group-card data-palette="{{ $colour['name'] }}">
                @endif
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-[12px] text-ink" style="background-color: var(--pal-accent);" aria-hidden="true">
                        <x-icon name="folder" size="18" />
                    </span>

                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="flex items-center gap-1.5">
                            <span class="rounded-pill bg-white/80 px-2 py-px text-label font-extrabold tracking-[-0.01em] text-ink">{{ $g->subject->code }}</span>
                            @if ($g->relationLoaded('section'))<x-section-chip :section="$g->section" :code="$g->subject->code" compact />@endif
                        </span>
                        <span class="truncate text-nav font-bold" title="{{ $g->name }}">{{ $g->name }}</span>
                        @if ($text !== null)
                            <span class="truncate text-label font-medium text-ink-400" data-group-secondary>{{ $text }}</span>
                        @endif
                    </span>
                @if ($route)
                    </a>
                @else
                    </button>
                @endif
            @endforeach
        </div>
    </div>
@endif