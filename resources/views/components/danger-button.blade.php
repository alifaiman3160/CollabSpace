<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'inline-flex h-[50px] items-center justify-center gap-2.5 rounded-pill bg-danger px-6 '
             .'text-nav font-bold text-white transition-colors hover:bg-danger-deep '
             .'focus:outline-none focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:ring-offset-2',
]) }}>
    {{ $slot }}
</button>
