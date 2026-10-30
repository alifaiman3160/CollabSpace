@props([
    'on' => 'confirm',   // name of the Alpine variable in the enclosing x-data
])

{{--
    One dialog per page, driven by an Alpine object rather than one modal per
    row. Open it by setting the variable named in `on`:

        x-on:click="confirm = {
            url: '{{ route('lecturer.subjects.destroy', 1) }}',
            method: 'DELETE',                  // optional, defaults to PUT
            title: 'Delete this subject?',
            body: '...',
            consequences: ['3 groups', '27 tasks'],   // optional cascade list
            confirmText: 'SECJ3104',           // optional: must be typed first
            action: 'Delete subject',
            tone: 'danger',
        }"

    Every destructive action routes through here, so nothing fires on a single
    stray click.

    `confirmText` adds a second, deliberate step: the button stays disabled
    until that exact text is typed. Reserved for the deletions that cascade —
    a subject takes its groups, and a group takes its tasks, documents, chat
    and contribution history with it, none of which can be restored. For
    everything else the dialog alone is enough, and asking people to type a
    name before removing one notification would train them to type it without
    reading, which is the opposite of what this is for.
--}}
<div x-show="{{ $on }}"
     x-cloak
     {{-- `typed` belongs to the dialog, not to the twelve screens that open it,
          so it is declared here and cleared every time the dialog opens — a
          value left over from the last deletion would arm the button before the
          reader had looked at it. --}}
     x-data="{
         typed: '',
         /*
          * Whether the confirm button is held shut.
          *
          * A method returning a real boolean, not an inline `confirmText &&
          * ...` expression. That expression yields the *value* of confirmText
          * when it is falsy, and Alpine only strips a bound boolean attribute
          * for exactly null, undefined or false — every other falsy value sets
          * it. So a payload with no typing step could end up with a disabled
          * button that nothing could ever re-enable, because there was no field
          * to type into. Returning false outright cannot do that.
          */
         locked(payload) {
             const required = payload?.confirmText

             return required ? this.typed.trim() !== required : false
         },
     }"
     x-effect="{{ $on }}; typed = ''"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     role="dialog"
     aria-modal="true"
     x-on:keydown.escape.window="{{ $on }} = null">

    <div class="absolute inset-0 bg-ink/40"
         x-on:click="{{ $on }} = null"
         x-transition:enter="transition ease-motion duration-motion-slow"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"></div>

    <div class="relative w-full max-w-md rounded-panel bg-surface p-7 shadow-modal"
         x-transition:enter="transition ease-motion duration-motion-slow"
         x-transition:enter-start="opacity-0 scale-[.96]"
         x-transition:enter-end="opacity-100 scale-100">

        <div class="flex items-start gap-3.5">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-[14px]"
                  :class="{{ $on }}?.tone === 'danger' ? 'bg-danger-bg text-danger-text' : 'bg-surface-muted text-ink-600'">
                <x-icon name="alert" size="20" />
            </span>

            <div class="flex min-w-0 flex-col gap-1.5">
                <h2 class="text-h3 font-bold tracking-title" x-text="{{ $on }}?.title"></h2>
                <p class="text-body leading-[1.55] text-ink-400" x-text="{{ $on }}?.body"></p>
            </div>
        </div>

        {{-- Cascade detail: what is about to be destroyed along with it. --}}
        <template x-if="{{ $on }}?.consequences?.length">
            <div class="mt-4 rounded-card bg-danger-bg px-4 py-3.5">
                <p class="text-label font-bold uppercase tracking-eyebrow text-danger-text">
                    This also deletes
                </p>
                <ul class="mt-2 flex list-disc flex-col gap-1 pl-4 text-body font-semibold text-danger-text">
                    <template x-for="line in {{ $on }}.consequences" :key="line">
                        <li x-text="line"></li>
                    </template>
                </ul>
            </div>
        </template>

        {{-- The second step, when the payload asks for one. Typed rather than
             clicked, because a second button in the same place as the first is
             something a hand already moving will press anyway. --}}
        <template x-if="{{ $on }}?.confirmText">
            <div class="mt-5 flex flex-col gap-2">
                <label class="text-meta font-semibold text-ink-600" :for="'confirm-text'">
                    Type <span class="font-bold text-ink" x-text="{{ $on }}?.confirmText"></span> to confirm
                </label>
                <input id="confirm-text"
                       type="text"
                       x-model="typed"
                       x-ref="typed"
                       autocomplete="off"
                       autocapitalize="off"
                       spellcheck="false"
                       class="h-[46px] w-full rounded-[14px] border border-surface-line bg-surface px-4 text-[14px] font-medium text-ink placeholder:text-ink-300 focus:outline-none focus:ring-2 focus:ring-danger/25"
                       :placeholder="{{ $on }}?.confirmText">
            </div>
        </template>

        <div class="mt-7 flex flex-wrap justify-end gap-3">
            <x-button variant="secondary" x-on:click="{{ $on }} = null">Cancel</x-button>

            {{-- Route mode: the payload names a URL, and the dialog posts to it. --}}
            <template x-if="{{ $on }}?.url">
                <form method="POST" :action="{{ $on }}.url">
                    @csrf
                    {{-- Spoofed verb comes from the payload; PUT unless it says otherwise. --}}
                    <input type="hidden" name="_method" :value="{{ $on }}.method || 'PUT'">

                    <button type="submit"
                            :disabled="locked({{ $on }})"
                            class="inline-flex items-center justify-center gap-2 rounded-pill px-5 py-3 text-nav font-bold text-white btn-motion disabled:cursor-not-allowed disabled:opacity-40"
                            :class="{{ $on }}.tone === 'danger' ? 'bg-danger hover:bg-danger-deep' : 'bg-ink hover:bg-ink-hover'"
                            x-text="{{ $on }}.action"></button>
                </form>
            </template>

            {{-- Livewire mode: the payload names a component method to call.
                 Added so the Livewire screens stop falling back on the
                 browser's own confirm() box, which is a grey system dialog
                 carrying the site's hostname — the one confirmation in the app
                 that looked like it belonged to something else. --}}
            <template x-if="! {{ $on }}?.url && {{ $on }}?.call">
                <button type="button"
                        :disabled="locked({{ $on }})"
                        x-on:click="$wire[{{ $on }}.call](...({{ $on }}.args || [])); {{ $on }} = null"
                        class="inline-flex items-center justify-center gap-2 rounded-pill px-5 py-3 text-nav font-bold text-white btn-motion disabled:cursor-not-allowed disabled:opacity-40"
                        :class="{{ $on }}.tone === 'danger' ? 'bg-danger hover:bg-danger-deep' : 'bg-ink hover:bg-ink-hover'"
                        x-text="{{ $on }}.action"></button>
            </template>
        </div>
    </div>
</div>
