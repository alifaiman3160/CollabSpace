@props(['status'])

@if ($status)
    <div {{ $attributes->merge([
        'class' => 'rounded-card bg-success-bg px-4 py-3 text-body font-semibold text-success-text',
    ]) }}>
        {{ $status }}
    </div>
@endif
