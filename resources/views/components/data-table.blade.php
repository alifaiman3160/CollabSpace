@props([
    // Column labels. A plain string, or [label, 'right'] for a numeric column so
    // the heading lines up with its figures.
    'headings' => [],
    'empty'    => 'No records to show.',
    // Quiet header (small, muted, hairline) is the house style for every table.
    // Pass :quiet="false" only to get the older heavier header back.
    'quiet'    => true,
    // Below this width the table scrolls sideways inside its card instead of
    // squeezing its columns or widening the page. Defaults from the column count.
    'minWidth' => null,
])

@php
    $columns = max(count($headings), 1);
    $minWidth ??= min(900, max(480, $columns * 130)).'px';
@endphp

{{--
    A white card on the soft page background. That contrast is what makes it
    read as a card; the border and shadow are the secondary cues.

    Cell padding is set once in app.css against the `.data-table` class rather
    than repeated on every <td> in every page, so row density is a single
    decision. Pages should not put px-*/py-* on their cells.

    Rows get their hover shift, and the last row loses its divider, from app.css.
    Pages write bare <tr>. Figures go in <td class="num"> to right-align with
    tabular digits. The cell types (two-line names, avatar stacks, progress,
    dates, status pills) are the <x-table.*> components, never rebuilt per page.
--}}
<div {{ $attributes->class('overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle') }}>
    <div class="overflow-x-auto">
        <table class="data-table w-full border-collapse text-left" style="min-width: {{ $minWidth }}">
            @if(filled($headings))
                <thead class="{{ $quiet ? 'bg-surface' : 'bg-surface-soft' }}">
                    <tr class="border-b {{ $quiet ? 'border-surface-line' : 'border-surface-strong' }}">
                        @foreach($headings as $heading)
                            @php
                                [$label, $align] = is_array($heading) ? [$heading[0], $heading[1] ?? 'left'] : [$heading, 'left'];
                            @endphp
                            <th class="{{ $quiet ? 'text-tiny font-semibold text-ink-300' : 'text-label font-bold text-ink/[.55]' }} uppercase tracking-eyebrow whitespace-nowrap {{ $align === 'right' ? 'text-right' : '' }}">
                                {{ $label }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
            @endif

            <tbody class="divide-y divide-surface-line text-body">
                @if(trim($slot) === '')
                    <tr>
                        <td colspan="{{ $columns }}" class="!py-10 text-center text-meta text-ink-400">
                            {{ $empty }}
                        </td>
                    </tr>
                @else
                    {{ $slot }}
                @endif
            </tbody>
        </table>
    </div>

    @if(isset($footer))
        <div class="border-t border-surface-line px-5 py-3">{{ $footer }}</div>
    @endif
</div>
