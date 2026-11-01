@props([
    'label',          // what a screen reader says on the trigger (it has no visible text of its own)
])

{{--
    A trigger with a rich tooltip: whatever is in the content slot is drawn in the app's tooltip layer on hover and on
    keyboard focus (resources/js/tooltip.js). The trigger can be focused, and carries the label for a screen reader, so
    nothing depends on a title attribute. Plain text hints do not need this: use data-tooltip="…" on any element.

    <x-tooltip label="Farah, 18 points, 11 percent" class="h-full" style="…">
        {{ trigger markup, if any }}
        <x-slot:content> rich markup </x-slot:content>
    </x-tooltip>
--}}
<span {{ $attributes->merge(['tabindex' => '0', 'aria-label' => $label, 'data-tooltip-rich' => 'true']) }}>{{ $slot }}<template data-tooltip-content>{{ $content }}</template></span>