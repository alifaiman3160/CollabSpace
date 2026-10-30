@props([
    'label' => 'More actions',
])

{{--
    A row's actions. At 640px and up everything sits in one line, the extras
    first and the primary action (the named slot) last. Below 640px the extras
    collapse into a "More" menu so a row with several actions does not wrap into
    a tower, while the primary action stays on screen.

    One copy of the markup serves both: the extras are an ordinary inline group on
    wide screens and a small floating menu on narrow ones. The menu is a real
    disclosure: a button with aria-expanded, closes on Escape, on an outside tap
    and when an item is chosen, and returns focus to the button. It is `fixed`
    rather than `absolute` because the table's scroll container would clip it.
--}}
<div {{ $attributes->class('flex items-center justify-end gap-1.5') }}
     x-data="{
         open: false,
         top: 0,
         right: 0,
         toggle() {
             if (! this.open) {
                 const box = this.$refs.more.getBoundingClientRect()
                 this.top = box.bottom + 6
                 this.right = Math.max(8, window.innerWidth - box.right)
             }
             this.open = ! this.open
         },
         close(refocus = false) {
             this.open = false
             if (refocus) this.$refs.more.focus()
         },
     }"
     x-on:keydown.escape.window="open && close(true)"
     x-on:click.outside="close()"
     x-on:scroll.window="close()"
     x-on:resize.window="close()">

    <button type="button" x-ref="more" x-on:click="toggle()"
            class="interactive inline-flex items-center gap-1.5 rounded-pill bg-surface-muted px-3 py-2 text-meta font-semibold text-ink-600 hover:bg-surface-hover sm:hidden"
            aria-haspopup="true" x-bind:aria-expanded="open" aria-label="{{ $label }}">
        <x-icon name="list" size="15" />
        More
    </button>

    <div class="hidden sm:flex sm:items-center sm:gap-1.5"
         x-bind:class="open ? '!flex flex-col items-stretch gap-1 fixed z-40 min-w-[180px] rounded-card border border-surface-strong bg-surface p-1.5 shadow-card [&>*]:justify-start' : ''"
         x-bind:style="open ? `top: ${top}px; right: ${right}px` : ''"
         x-on:click="open && close()"
         role="group" aria-label="{{ $label }}">
        {{ $slot }}
    </div>

    {{ $primary ?? '' }}
</div>
