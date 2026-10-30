{{-- Design: 52px, pill, ink, raised shadow. --}}
<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'inline-flex h-[52px] items-center justify-center gap-[9px] rounded-pill bg-ink px-6 '
             .'text-[14.5px] font-bold tracking-[-0.01em] text-white shadow-button '
             .'btn-motion hover:bg-ink-hover hover:shadow-button-hover focus:outline-none focus-visible:ring-2 '
             .'focus-visible:ring-ink/30 focus-visible:ring-offset-2 disabled:opacity-50',
]) }}>
    {{ $slot }}
</button>
