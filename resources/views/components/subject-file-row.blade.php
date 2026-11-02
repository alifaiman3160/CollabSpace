@props([
    'file',                 // a SubjectFile
    'manage' => false,      // lecturer: shows the original name, the date and a slot for the delete action
    'scope' => null,        // which assessment it is for ("Assignment 1", "Whole subject"), when the page says
])

{{-- One file of a subject: kind chip, title, size and a download link. The same row for the student and the lecturer. --}}
<li {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-card border border-surface-line bg-surface px-3.5 py-2.5') }} data-subject-file>
    <span class="inline-flex shrink-0 items-center rounded-pill bg-surface-muted px-2 py-px text-label font-bold text-ink-600" data-file-kind>{{ $file->kindLabel() }}</span>

    <span class="flex min-w-0 flex-1 flex-col">
        <a href="{{ route('subject-files.download', $file) }}" @if ($file->opensInBrowser()) target="_blank" rel="noopener" @endif
           class="interactive truncate rounded-md text-meta font-bold text-periwinkle-link hover:underline" data-file-download>{{ $file->title }}</a>
        @if ($manage)
            <span class="truncate text-label text-ink-400" title="{{ $file->original_name }}">{{ $file->original_name }} · uploaded {{ $file->created_at->format('j M Y') }}</span>
        @endif
    </span>

    @if ($scope)
        <span class="inline-flex shrink-0 items-center rounded-pill border border-surface-strong bg-surface px-2 py-px text-label font-bold text-ink-600" data-file-scope>{{ $scope }}</span>
    @endif

    <span class="shrink-0 text-label font-semibold text-ink-400">{{ $file->sizeLabel() }}</span>

    {{ $slot }}
</li>
