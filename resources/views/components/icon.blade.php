@props([
    'name' => 'circle',
    'size' => 18,
])

@php
    // Lucide paths at 24x24 viewBox — the designs use these at 18x18 / stroke 1.7.
    $paths = [
        'home'        => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'book'        => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'        => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'graduation'  => '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5"/>',
        'file'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'edit'        => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
        'chat'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'bell'        => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'chart'       => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
        'check'       => '<path d="M20 6 9 17l-5-5"/>',
        'clock'       => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'plus'        => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'folder'      => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/>',
        'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'chevron'     => '<path d="m9 18 6-6-6-6"/>',
        'alert'       => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'inbox'       => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'circle'      => '<circle cx="12" cy="12" r="10"/>',
        // Added for the sign-in screens.
        'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2.5"/><path d="m3 6.5 9 6.5 9-6.5"/>',
        'lock'        => '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'eye'         => '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'     => '<path d="M9.6 5.6A8.6 8.6 0 0 1 12 5.3c6.4 0 10 6.5 10 6.5a18 18 0 0 1-2.9 3.7"/><path d="M6.3 7.3A18 18 0 0 0 2 12s3.6 6.5 10 6.5c1.6 0 3-.3 4.3-.9"/><path d="M3.8 3.8l16.4 16.4"/>',
        'arrow-right' => '<path d="M4 12h13"/><path d="m13 7 5 5-5 5"/>',
        'shield'      => '<path d="M12 2.6 4.4 6.4v5.2c0 4.1 3.2 7.5 7.6 8.6 4.4-1.1 7.6-4.5 7.6-8.6V6.4z"/><path d="m8.8 12 2.3 2.2 4-4.6"/>',
        // Added for the task board.
        'trash'       => '<path d="M3.5 6h17"/><path d="M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6"/><path d="M18.5 6l-.8 12.1a1.6 1.6 0 0 1-1.6 1.4H7.9a1.6 1.6 0 0 1-1.6-1.4L5.5 6"/><path d="M10 10.5v5M14 10.5v5"/>',
        'drag'        => '<circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>',
        // Added for the document editor.
        'list'        => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1.2"/><circle cx="4.5" cy="12" r="1.2"/><circle cx="4.5" cy="18" r="1.2"/>',
        'list-ordered'=> '<path d="M10 6h10M10 12h10M10 18h10"/><path d="M4 4.5h1.5V9M3.6 15.2c0-.7.6-1.2 1.3-1.2s1.3.5 1.3 1.2c0 1.1-2.6 1.6-2.6 3.3h2.8"/>',
        'quote'       => '<path d="M9 7.5H5.5A1.5 1.5 0 0 0 4 9v3.5h5V9M20 7.5h-3.5A1.5 1.5 0 0 0 15 9v3.5h5V9"/><path d="M9 12.5c0 2.4-1.4 3.6-3.5 4M20 12.5c0 2.4-1.4 3.6-3.5 4"/>',
        'history'     => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1"/><path d="M3 4.5V9h4.5"/><path d="M12 7.5V12l3 1.8"/>',
        'restore'     => '<path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M21 4.5V9h-4.5"/>',
        'download'    => '<path d="M12 3.5v10.5"/><path d="m7.8 10 4.2 4.2 4.2-4.2"/><path d="M4.5 18.5h15"/>',
        'arrow-left'  => '<path d="M20 12H7"/><path d="m11 17-5-5 5-5"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'shrink-0']) }}
     width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.7"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths[$name] ?? $paths['circle'] !!}
</svg>
