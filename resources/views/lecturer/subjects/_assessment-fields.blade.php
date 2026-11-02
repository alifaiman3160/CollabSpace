{{-- The fields of one assessment form. Expects $values (an Assessment or null), $old (use the old input), $idPrefix. --}}
@php
    $pick = fn (string $field, $fallback) => $old ? old($field, $fallback) : $fallback;
@endphp

<div class="grid items-start gap-3 sm:grid-cols-2">
    <div class="flex flex-col gap-2 sm:col-span-2">
        <x-input-label for="{{ $idPrefix }}_title" value="Title" />
        <x-text-input id="{{ $idPrefix }}_title" name="title" type="text" required maxlength="80"
                      :value="$pick('title', $values?->title)" placeholder="For example: Assignment 1" />
    </div>

    <div class="flex flex-col gap-2 sm:col-span-2">
        <x-input-label for="{{ $idPrefix }}_brief" value="Brief" />
        <textarea id="{{ $idPrefix }}_brief" name="brief" rows="4"
                  placeholder="What the groups have to produce for this assessment, and what it is marked on."
                  class="w-full rounded-[16px] bg-surface border border-surface-line px-[18px] py-3.5 text-[14px] font-medium text-ink placeholder:text-ink-300 focus:outline-none focus:ring-2 focus:ring-ink/10">{{ $pick('brief', $values?->brief) }}</textarea>
        <p class="text-meta text-ink-400">
            Shown to the students of this subject, on their group pages. Leave blank until you have written it.
        </p>
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="{{ $idPrefix }}_type" value="Type" />
        <select id="{{ $idPrefix }}_type" name="type"
                class="h-[52px] w-full rounded-[16px] bg-surface border border-surface-line px-[18px] text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
            @foreach (\App\Models\Assessment::TYPES as $value => $label)
                <option value="{{ $value }}" @selected($pick('type', $values?->type ?? 'assignment') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="{{ $idPrefix }}_weight" value="Weight % (optional)" />
        <x-text-input id="{{ $idPrefix }}_weight" name="weight" type="number" min="0" max="100" step="0.01" inputmode="decimal"
                      :value="$pick('weight', $values?->weight)" class="max-w-[140px]" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="{{ $idPrefix }}_starts" value="Start date" />
        <x-text-input id="{{ $idPrefix }}_starts" name="starts_on" type="date"
                      :value="$pick('starts_on', $values?->starts_on?->format('Y-m-d'))" class="max-w-[200px]" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="{{ $idPrefix }}_due" value="Due date" />
        <x-text-input id="{{ $idPrefix }}_due" name="due_on" type="date"
                      :value="$pick('due_on', $values?->due_on?->format('Y-m-d'))" class="max-w-[200px]" />
    </div>

    <p class="text-meta text-ink-400 sm:col-span-2">
        The weight is for information only. Leave a date empty to leave that end open.
    </p>
</div>
