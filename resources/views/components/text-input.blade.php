@props(['disabled' => false])

{{-- White with a border rather than a soft fill: the page background is now
     the soft token, so a soft field would disappear on any screen whose form
     is not inside a card. --}}
<input @disabled($disabled) {{ $attributes->merge([
    'class' => 'h-[52px] w-full rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] '
             .'font-medium text-ink placeholder:text-ink-200 focus:outline-none focus:ring-2 focus:ring-ink/10',
]) }}>
