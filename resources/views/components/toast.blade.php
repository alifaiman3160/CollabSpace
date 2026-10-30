{{--
    Success and failure feedback, as a popup rather than a banner in the page.

    The old inline banner was easy to miss: it rendered wherever <x-flash /> had
    been placed, which on a long screen was often below the fold, so creating an
    account looked exactly like doing nothing. This floats above the page in a
    fixed corner, so it is seen wherever the reader happens to be looking.

    Mounted once in the layout, so every screen gets it without remembering to —
    the previous arrangement needed each view to opt in, and six of them had not.

    Two ways to raise one:

      a redirect      ->  ->with('status', '...')  or  ->withErrors(['status' => '...'])
      a Livewire hit  ->  $this->dispatch('toast', message: '...', tone: 'danger')

    The second exists because a Livewire action never reloads the page, so a
    session flash would sit unread until the next full request. Both paths land
    in the same component: one message style for the whole application, rather
    than a second inline notice growing beside this one and drifting from it.

    Auto-dismisses after a while, because a message about something that already
    happened should not need clearing by hand. Failures stay noticeably longer
    than successes: "saved" is confirmation of what you expected, while an error
    is news you may need to read twice. Hovering pauses the countdown, so a long
    message cannot time out while it is being read.

    The temporary-password panel is deliberately NOT routed through here — see
    <x-flash />. That one must not disappear on a timer, because the password is
    shown exactly once and cannot be recovered afterwards.
--}}
@php
    $fromSession = session('status') && ! session('temporary_password');
    $fromErrors = $errors->has('status');

    $initialMessage = match (true) {
        $fromErrors => $errors->first('status'),
        $fromSession => session('status'),
        default => null,
    };
    $initialTone = $fromErrors ? 'danger' : 'success';
@endphp

{{-- Always rendered, even with nothing to say, so the Livewire listener below
     exists before the first action fires. Empty it is inert and invisible. --}}
<div class="pointer-events-none fixed inset-x-0 top-5 z-[60] flex justify-center px-4 sm:inset-x-auto sm:right-6 sm:justify-end"
     x-data="{
         shown: false,
         message: '',
         tone: 'success',
         timer: null,

         show(message, tone = 'success') {
             if (! message) return

             this.message = message
             this.tone = tone === 'danger' ? 'danger' : 'success'
             this.shown = true
             this.start()
         },

         /*
          * Roughly how long it takes to read, with a floor and a ceiling. A
          * fixed five seconds is too long for 'Saved' and far too short for a
          * sentence explaining why something was refused.
          */
         start() {
             clearTimeout(this.timer)

             const words = this.message.trim().split(/\s+/).length
             const floor = this.tone === 'danger' ? 6000 : 4000

             this.timer = setTimeout(() => this.shown = false, Math.max(floor, Math.min(12000, words * 380)))
         },
     }"
     x-init="$nextTick(() => show(@js($initialMessage), @js($initialTone)))"
     x-on:toast.window="show($event.detail.message, $event.detail.tone)"
     role="status"
     aria-live="polite">

    <div x-show="shown"
         x-cloak
         x-on:mouseenter="clearTimeout(timer)"
         x-on:mouseleave="start()"
         x-on:keydown.escape.window="shown = false"
         x-transition:enter="transition ease-motion duration-motion-slow"
         x-transition:enter-start="opacity-0 scale-[.96]"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-motion duration-motion-fast"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-[.96]"
         class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-panel border px-5 py-4 shadow-card"
         :class="tone === 'danger' ? 'border-danger-line bg-danger-bg' : 'border-success-line bg-success-bg'">

        <span class="flex size-8 shrink-0 items-center justify-center rounded-pill"
              :class="tone === 'danger' ? 'bg-danger text-white' : 'bg-success text-white'">
            <template x-if="tone === 'danger'">
                <x-icon name="alert" size="16" class="[stroke-width:2.5]" />
            </template>
            <template x-if="tone !== 'danger'">
                <x-icon name="check" size="16" class="[stroke-width:2.5]" />
            </template>
        </span>

        <p class="min-w-0 flex-1 pt-1 text-body font-semibold leading-[1.45]"
           :class="tone === 'danger' ? 'text-danger-text' : 'text-success-text'"
           x-text="message"></p>

        <button type="button"
                x-on:click="shown = false"
                class="flex size-7 shrink-0 items-center justify-center rounded-pill interactive"
                :class="tone === 'danger' ? 'text-danger-text hover:bg-danger/15' : 'text-success-text hover:bg-success/15'"
                aria-label="Dismiss this message">
            <x-icon name="plus" size="15" class="rotate-45 [stroke-width:2.5]" />
        </button>
    </div>
</div>
