@props([
    'headings' => [],     // array of column labels
    'empty'    => 'No records to show.',
])

{{--
    A white card on the soft page background. That contrast is what makes it
    read as a card; the border and shadow are the secondary cues.

    Cell padding is set once in app.css against the `.data-table` class rather
    than repeated on every <td> in every page, so row density is a single
    decision. Pages should not put px-*/py-* on their cells.
--}}
<div {{ $attributes->class('overflow-hidden rounded-panel border border-surface-line bg-surface shadow-subtle') }}>
    <div class="overflow-x-auto">
        <table class="data-table w-full border-collapse text-left">
            @if(filled($headings))
                <thead class="bg-surface-soft">
                    <tr class="border-b border-surface-strong">
                        @foreach($headings as $heading)
                            <th class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55] whitespace-nowrap">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
            @endif

            <tbody class="divide-y divide-surface-line text-body">
                @if(trim($slot) === '')
                    <tr>
                        <td colspan="{{ max(count($headings), 1) }}" class="!py-10 text-center text-meta text-ink-400">
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
