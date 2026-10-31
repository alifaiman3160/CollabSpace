@props([
    'title',
    'count' => null,        // a small pill beside the title ("4")
    'label' => null,        // aria-label of the section; the title when omitted
])

{{--
    A panel card as the dashboard draws them: white, soft border, the title inside as its header (with an optional
    count), a body that fills the space and scrolls on its own, and an optional footer with the panel's one link.

    <x-panel title="Group members" :count="4"> … <x-slot:footer> … </x-slot:footer></x-panel>
--}}
<section {{ $attributes->class('relative flex min-h-[23.75rem] flex-col overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle') }}
         aria-label="{{ $label ?? $title }}">
    <h2 class="flex items-center gap-2 px-5 pb-2 pt-4 text-h3 font-extrabold tracking-title">
        {{ $title }}
        @if ($count !== null)
            <span class="rounded-pill bg-surface-muted px-2 py-0.5 text-label font-bold text-ink-600" data-panel-count>{{ $count }}</span>
        @endif
    </h2>

    @isset($aside)
        <span class="absolute right-5 top-[1.15rem] max-w-[55%] truncate text-label font-medium text-ink-400" data-panel-aside>{{ $aside }}</span>
    @endisset

    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 pb-3" data-panel-body>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-surface-line px-5 py-3">
            {{ $footer }}
        </div>
    @endisset
</section>