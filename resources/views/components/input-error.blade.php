@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'flex flex-col gap-1 text-label font-semibold text-danger-text']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
