@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-meta font-bold text-ink-700']) }}>
    {{ $value ?? $slot }}
</label>
