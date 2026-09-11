<button {{ $attributes->merge([
    'type' => 'button',
    'class' => 'inline-flex h-[50px] items-center justify-center gap-2.5 rounded-pill bg-surface-muted px-6 '
             .'text-nav font-bold text-ink transition-colors hover:bg-surface-hover '
             .'focus:outline-none focus-visible:ring-2 focus-visible:ring-ink/20',
]) }}>
    {{ $slot }}
</button>
