@props([
    'title' => 'Nothing here yet',
    'body'  => null,
    'cta'   => null,      // label for the button
    'href'  => null,
    'icon'  => 'inbox',
])

<div {{ $attributes->class('flex flex-col items-center justify-center gap-3 rounded-panel border border-dashed border-surface-strong bg-surface px-6 py-12 shadow-subtle text-center') }}>
    <span class="inline-flex size-11 items-center justify-center rounded-pill bg-surface-muted text-ink-300">
        <x-icon :name="$icon" />
    </span>

    <h3 class="text-h3 font-bold tracking-title">{{ $title }}</h3>

    @if(filled($body))
        <p class="max-w-sm text-body text-ink-400">{{ $body }}</p>
    @endif

    @if($cta)
        <x-button :href="$href" class="mt-2">{{ $cta }}</x-button>
    @endif

    {{ $slot }}
</div>
