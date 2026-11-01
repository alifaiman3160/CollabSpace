@props([
    'task',
    'mine' => true,        // the task is the viewer's own (or nobody's)
    'readonly' => false,   // the lecturer's board: details to read, no controls
])

{{--
    A finished task as a row that opens: one line shut (check, title, assignee, date, chevron), everything about it open.
    Shared by the student's board (with its controls in the `controls` slot) and the lecturer's read-only board. It
    needs the parent's Alpine scope to have `doneOpen` (the id of the one open row) and `toggleDone(id, el)`.
--}}
<article data-cs-item data-done-row
         {{ $attributes->merge(['class' => 'flex shrink-0 flex-col overflow-hidden rounded-card bg-surface shadow-subtle transition-shadow hover:shadow-card']) }}>

    <button type="button" x-on:click="toggleDone({{ $task->id }}, $el.closest('article'))" x-bind:aria-expanded="doneOpen === {{ $task->id }}" aria-controls="done-{{ $task->id }}" data-done-toggle
            class="motion-colors flex min-h-[44px] w-full shrink-0 items-center gap-2.5 px-3 py-2.5 text-left text-meta hover:bg-surface-soft">
        <span class="flex size-5 shrink-0 items-center justify-center rounded-pill bg-success-bg text-success-text" aria-hidden="true">
            <x-icon name="check" size="12" class="[stroke-width:3]" />
        </span>
        <span class="min-w-0 flex-1 truncate text-meta font-bold" title="{{ $task->title }}">{{ $task->title }}</span>
        @if ($task->assignee)
            <x-avatar :name="$task->assignee->name" size="sm" class="!size-[24px] !text-micro" />
        @endif
        @if ($task->completed_at ?? $task->deadline)
            <span class="shrink-0 text-label font-medium tabular-nums text-ink-400">{{ ($task->completed_at ?? $task->deadline)->format('j M') }}</span>
        @endif
        <svg class="cs-chevron size-4 shrink-0 text-ink-300" x-bind:class="doneOpen === {{ $task->id }} ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <x-collapse open="doneOpen === {{ $task->id }}" id="done-{{ $task->id }}">
        <div class="flex flex-col gap-2.5 px-3 pb-3 pt-1">
            @if ($task->description)
                <p class="break-words text-meta text-ink-600">{{ $task->description }}</p>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-meta">
                <span class="flex min-w-0 items-center gap-1.5 font-semibold text-ink-600">
                    <x-icon name="user" size="13" class="shrink-0 text-ink-300" />
                    <span class="truncate">{{ $task->assignee ? (($mine && ! $readonly) ? 'You' : $task->assignee->name) : 'Unassigned' }}</span>
                    @if (! $readonly && $task->assignee && ! $mine)
                        <span class="flex shrink-0 items-center text-ink-300" aria-label="Only {{ $task->assignee->name }} can move this card"><x-icon name="lock" size="12" /></span>
                    @endif
                </span>
                @if ($task->deadline)
                    <span class="flex shrink-0 items-center gap-1.5 font-semibold text-ink-400">
                        <x-icon name="calendar" size="13" />
                        Deadline {{ $task->deadline->format('j M') }}
                    </span>
                @endif
            </div>

            @if ($readonly)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-meta">
                    <span class="rounded-pill bg-success-bg px-[11px] py-[4.5px] text-label font-bold text-success-text">Done</span>
                    @if ($task->completed_at)
                        <span class="font-semibold text-ink-400">Completed {{ $task->completed_at->format('j M Y') }}</span>
                    @endif
                </div>
            @else
                {{ $controls ?? '' }}
            @endif
        </div>
    </x-collapse>
</article>
