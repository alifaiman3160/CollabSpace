@props(['items'])   {{-- [[label, url or null], …]: every part a link except the last --}}

<nav aria-label="Breadcrumb" class="mb-1.5 flex min-w-0 flex-wrap items-center gap-1.5 text-meta font-semibold text-ink-400">
    @foreach ($items as [$label, $url])
        @if (! $loop->first)
            <span aria-hidden="true" class="text-ink-300">›</span>
        @endif

        @if ($url && ! $loop->last)
            <a href="{{ $url }}" class="interactive max-w-[16rem] truncate rounded-md hover:text-ink">{{ $label }}</a>
        @else
            <span class="max-w-[20rem] truncate text-ink-600" @if ($loop->last) aria-current="page" @endif>{{ $label }}</span>
        @endif
    @endforeach
</nav>