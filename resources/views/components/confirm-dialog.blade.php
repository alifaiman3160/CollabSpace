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
            action: 'Delete subject',
            tone: 'danger',
        }"

    Every destructive action routes through here, so nothing fires on a single
    stray click.
--}}
<div x-show="{{ $on }}"
     x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     role="dialog"
     aria-modal="true"
     x-on:keydown.escape.window="{{ $on }} = null">

    <div class="absolute inset-0 bg-ink/40"
         x-on:click="{{ $on }} = null"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"></div>

    <div class="relative w-full max-w-md rounded-panel bg-surface p-7 shadow-modal"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
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

        <form method="POST" :action="{{ $on }}?.url" class="mt-7 flex flex-wrap justify-end gap-3">
            @csrf
            {{-- Spoofed verb comes from the payload; PUT unless it says otherwise. --}}
            <input type="hidden" name="_method" :value="{{ $on }}?.method || 'PUT'">

            <x-button variant="secondary" x-on:click="{{ $on }} = null">Cancel</x-button>

            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-pill px-5 py-3 text-nav font-bold text-white transition-colors"
                    :class="{{ $on }}?.tone === 'danger' ? 'bg-danger hover:bg-danger-deep' : 'bg-ink hover:bg-ink-hover'"
                    x-text="{{ $on }}?.action"></button>
        </form>
    </div>
</div>
