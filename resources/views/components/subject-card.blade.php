@props([
    'subject',              // the subject (id decides the colour: SubjectPalette, id mod 6)
    'stats' => [],          // [['label' => 'Students', 'value' => 12, 'href' => null], …]
    'href' => null,         // makes the subject's name a link
    'i' => 0,               // position, for the entrance stagger
])

@php
    $colour = \App\Support\SubjectPalette::for($subject->getKey());
@endphp

{{--
    A subject as a pastel card: the same card the dashboards use. Code chip, name, an optional row of small stats, then
    an optional footer for actions. Slots: default (under the name), badge (beside the code chip), footer (the actions).
--}}
<article {{ $attributes->class('cs-card-in cs-lift flex min-w-0 flex-col rounded-panel border text-ink') }}
         style="--i: {{ min($i, 12) }}; background-color: {{ $colour['bg'] }}; border-color: {{ $colour['border'] }};"
         data-subject-card data-palette="{{ $colour['name'] }}">
    <div class="flex flex-1 flex-col gap-4 p-5">
        <div class="flex items-start justify-between gap-2">
            <span class="rounded-pill px-2.5 py-0.5 text-nav font-extrabold tracking-[-0.02em]" style="background-color: {{ $colour['accent'] }};">{{ $subject->code }}</span>
            @isset($badge)
                {{ $badge }}
            @endisset
        </div>

        <div class="flex min-w-0 flex-col gap-1">
            @if ($href)
                <a href="{{ $href }}" class="interactive truncate rounded-md text-h2 font-extrabold leading-[1.15] tracking-title" title="{{ $subject->name }}">{{ $subject->name }}</a>
            @else
                <h2 class="truncate text-h2 font-extrabold leading-[1.15] tracking-title" title="{{ $subject->name }}">{{ $subject->name }}</h2>
            @endif

            {{ $slot }}
        </div>

        @if (count($stats) > 0)
            <dl class="mt-auto grid grid-cols-2 gap-2" data-subject-stats>
                @foreach ($stats as $stat)
                    <div class="flex flex-col gap-0.5 rounded-card bg-white/60 px-3 py-2">
                        <dt class="text-label font-semibold text-ink-600">{{ $stat['label'] }}</dt>
                        <dd class="truncate text-nav font-extrabold">
                            @if (! empty($stat['href']))
                                <a href="{{ $stat['href'] }}" class="interactive rounded-md text-periwinkle-link hover:underline">{{ $stat['value'] }}</a>
                            @else
                                {{ $stat['value'] }}
                            @endif
                        </dd>
                        @if (! empty($stat['pill']))
                            <x-table.dot-pill :tone="$stat['pill'][0]" class="w-fit">{{ $stat['pill'][1] }}</x-table.dot-pill>
                        @endif
                    </div>
                @endforeach
            </dl>
        @endif
    </div>

    @isset($footer)
        <div class="flex flex-wrap items-center gap-1.5 border-t px-3 py-3" style="border-color: {{ $colour['border'] }};">
            {{ $footer }}
        </div>
    @endisset
</article>