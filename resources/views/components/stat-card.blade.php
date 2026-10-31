@props([
    'label' => '',
    'value' => '',
    'meta'  => null,
    'tone'  => 'plain',      // plain | periwinkle | dark
    'icon'  => null,
    'size'  => 'lg',         // lg (hero stat) | sm (compact row, per the dashboard designs)
    'iconTone' => 'neutral', // sm only: neutral | info | success | danger — the icon chip
    'palette' => null,       // sm only: a SubjectPalette entry; the tile wears that soft colour instead of white
    'bind' => null,          // sm only: an Alpine expression for the big number (a tile that follows a filter)
    'metaBind' => null,      // sm only: the same for the caption
])

@php
    $tones = [
        'plain'      => 'bg-surface border border-surface-line shadow-subtle',
        'periwinkle' => 'bg-periwinkle border border-transparent',
        'dark'       => 'bg-ink border border-transparent text-white',
    ];

    $dark = $tone === 'dark';

    $labelTone = $dark ? 'text-white/55' : 'text-ink/[.55]';
    $metaTone  = $dark ? 'text-white/70' : 'text-ink-400';

    // Icon chip colours for the compact card (design: 34px, 12px radius).
    $chips = [
        'neutral' => 'bg-surface-muted text-ink-600',
        'info'    => 'bg-periwinkle-soft text-periwinkle-link',
        'success' => 'bg-success-bg text-success-text',
        'danger'  => 'bg-danger-bg text-danger-text',
    ];
    $chip = $dark ? 'bg-white/15 text-white' : ($chips[$iconTone] ?? $chips['neutral']);
@endphp

@if($size === 'sm')

    @php
        // One style attribute: the group's soft colour, then anything the caller passed.
        $style = trim(($palette ? "background-color: {$palette['bg']}; border-color: {$palette['border']}; " : '').$attributes->get('style', ''));
    @endphp

    <div {{ $attributes->except('style')->class([
            'flex min-w-[128px] flex-1 flex-col justify-between gap-2.5 rounded-panel p-4',
            $tones[$tone] ?? $tones['plain'],
       ]) }}
       @if ($style !== '') style="{{ $style }}" @endif
       @if ($palette) data-palette="{{ $palette['name'] }}" @endif>
        @if($icon)
            <span class="flex size-8 items-center justify-center rounded-[11px] {{ $chip }}">
                <x-icon :name="$icon" size="17" />
            </span>
        @endif

        <span class="flex flex-col gap-0.5">
            <span class="text-label font-bold uppercase tracking-eyebrow {{ $labelTone }}">{{ $label }}</span>
            <span class="text-h1 font-extrabold leading-[1.05] tracking-stat" @if ($bind) x-text="{{ $bind }}" @endif>{{ $value }}</span>
        </span>

        @if(filled($meta))
            <span class="text-label font-semibold {{ $metaTone }}" @if ($metaBind) x-text="{{ $metaBind }}" @endif>{{ $meta }}</span>
        @endif

        {{ $slot }}
    </div>

@else

    <div {{ $attributes->class([
            'flex flex-col gap-1.5 rounded-panel p-5',
            $tones[$tone] ?? $tones['plain'],
       ]) }}>
        <div class="flex items-start justify-between gap-3">
            <span class="text-label font-bold uppercase tracking-eyebrow {{ $labelTone }}">{{ $label }}</span>
            @if($icon)
                <x-icon :name="$icon" class="{{ $dark ? 'text-white/70' : 'text-ink-300' }}" />
            @endif
        </div>

        <span class="text-stat font-extrabold tracking-stat">{{ $value }}</span>

        @if(filled($meta))
            <span class="text-meta font-medium {{ $metaTone }}">{{ $meta }}</span>
        @endif

        {{ $slot }}
    </div>

@endif
