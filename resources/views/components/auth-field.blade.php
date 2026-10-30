@props([
    'name',
    'label',
    'icon' => null,
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'reveal' => false,
    'aside' => null,
])

{{--
    Labelled input for the auth screens, per docs/design/CollabSpace Sign In.dc.html:
    52px pill-less field on surface-soft, 16px radius, leading stroke icon.

    `reveal` adds the show/hide password toggle from the design (Alpine).
    `aside` renders on the label row, right-aligned — used for "Forgot?".
--}}
<label class="flex flex-col gap-2" @if($reveal) x-data="{ show: false }" @endif>

    <span class="flex items-baseline justify-between gap-3">
        <span class="text-meta font-bold text-ink-700">{{ $label }}</span>
        @if($aside){{ $aside }}@endif
    </span>

    <span class="flex h-[52px] items-center gap-[11px] rounded-[16px] bg-surface-soft px-[18px]
                 focus-within:ring-2 focus-within:ring-ink/10">

        @if($icon)
            <x-icon :name="$icon" size="18" class="text-ink-300" />
        @endif

        <input
            id="{{ $name }}"
            name="{{ $name }}"
            @if($reveal)
                :type="show ? 'text' : 'password'"
            @else
                type="{{ $type }}"
            @endif
            @if($value !== null) value="{{ $value }}" @endif
            @if($placeholder) placeholder="{{ $placeholder }}" @endif
            {{ $attributes->merge([
                'class' => 'min-w-0 flex-1 border-none bg-transparent p-0 text-[14px] font-medium text-ink outline-none focus:ring-0',
            ]) }} />

        @if($reveal)
            <button type="button"
                    x-on:click="show = ! show"
                    :aria-label="show ? 'Hide password' : 'Show password'"
                    class="flex shrink-0 items-center text-ink-300 interactive hover:text-ink">
                <span x-show="! show"><x-icon name="eye" size="18" /></span>
                <span x-show="show" x-cloak><x-icon name="eye-off" size="18" /></span>
            </button>
        @endif
    </span>

    <x-input-error :messages="$errors->get($name)" />
</label>
